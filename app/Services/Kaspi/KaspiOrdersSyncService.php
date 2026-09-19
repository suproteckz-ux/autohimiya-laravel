<?php

namespace App\Services\Kaspi;

use App\Enums\KaspiOrderInternalStatus;
use App\Models\KaspiOrder;
use App\Models\KaspiOrderItem;
use App\Models\KaspiStockEventLog;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class KaspiOrdersSyncService
{
    private array $skuCache = [];

    public function __construct(
        private readonly KaspiOrdersClient $client,
        private readonly KaspiReservationEngine $reservationEngine,
        private readonly KaspiHandoffDetector $handoffDetector,
    ) {}

    public function sync(array $options = []): array
    {
        $lockTimeout = 300;
        $lock = Cache::lock('kaspi:orders-sync', $lockTimeout);

        if (! $lock->get()) {
            return $this->skippedResult('Another sync is already running.');
        }

        try {
            return $this->syncUnlocked($options);
        } finally {
            optional($lock)->release();
        }
    }

    private function syncUnlocked(array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $fromDate = isset($options['from']) ? Carbon::parse($options['from']) : Carbon::now()->subDays(7);
        $toDate = isset($options['to']) ? Carbon::parse($options['to']) : Carbon::now();
        $singleOrderId = $options['order'] ?? null;
        $isObserve = $this->reservationEngine->isObserveMode();
        $mode = $isObserve ? 'observe' : 'active';

        $created = $updated = $skuMatched = $skuUnmatched = $errors = 0;
        $processed = 0;

        try {
            if ($singleOrderId) {
                $pages = $this->client->getAllOrders(['code' => $singleOrderId]);
            } else {
                $filter = [
                    'creationDate[$ge]' => $fromDate->getTimestampMs(),
                    'creationDate[$le]' => $toDate->getTimestampMs(),
                ];
                $pages = $this->client->getAllOrders($filter);
            }

            foreach ($pages as $batch) {
                foreach ($batch as $orderData) {
                    if ($limit > 0 && $processed >= $limit) {
                        break 2;
                    }

                    try {
                        $result = $this->processOrder($orderData, $dryRun);
                        $processed++;

                        if ($result['is_new']) {
                            $created++;
                        } else {
                            $updated++;
                        }

                        $skuMatched += $result['sku_matched'];
                        $skuUnmatched += $result['sku_unmatched'];
                    } catch (Throwable $e) {
                        $errors++;
                        report($e);
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
            return [
                'successful' => false,
                'message' => 'Sync failed: '.$e->getMessage(),
                'mode' => $mode,
                'processed' => $processed,
                'created' => $created,
                'updated' => $updated,
                'errors' => $errors,
            ];
        }

        return [
            'successful' => true,
            'mode' => $mode,
            'dry_run' => $dryRun,
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'sku_matched' => $skuMatched,
            'sku_unmatched' => $skuUnmatched,
            'errors' => $errors,
        ];
    }

    private function processOrder(array $orderData, bool $dryRun): array
    {
        $attrs = $orderData['attributes'] ?? [];
        $kaspiOrderId = $orderData['id'] ?? null;

        if (! $kaspiOrderId) {
            throw new \RuntimeException('Order data missing id.');
        }

        $isNew = false;
        $order = KaspiOrder::where('kaspi_order_id', $kaspiOrderId)->first();

        if (! $order) {
            $isNew = true;
        }

        if ($dryRun) {
            return ['is_new' => $isNew, 'sku_matched' => 0, 'sku_unmatched' => 0];
        }

        return DB::transaction(function () use ($orderData, $attrs, $kaspiOrderId, $isNew) {
            $order = KaspiOrder::firstOrNew(['kaspi_order_id' => $kaspiOrderId]);
            $wasNew = ! $order->exists;

            $courierDate = $this->parseTimestamp($attrs['courierTransmissionDate'] ?? null);
            $courierPlanDate = $this->parseTimestamp($attrs['courierTransmissionPlanningDate'] ?? null);
            $kaspiCreatedAt = $this->parseTimestamp($attrs['creationDate'] ?? null);
            $completionDate = $this->parseTimestamp($attrs['completionDate'] ?? null);

            $order->fill([
                'kaspi_code' => $attrs['code'] ?? null,
                'kaspi_status' => $attrs['status'] ?? null,
                'kaspi_state' => $attrs['state'] ?? null,
                'delivery_type' => $attrs['deliveryMode'] ?? $attrs['state'] ?? null,
                'kaspi_created_at' => $kaspiCreatedAt,
                'courier_transmission_planning_date' => $courierPlanDate,
                'courier_transmission_date' => $courierDate,
                'completed_at' => $completionDate,
                'waybill' => $attrs['waybill'] ?? ($attrs['kaspiDelivery']['waybill'] ?? null),
                'waybill_number' => $attrs['waybillNumber'] ?? null,
                'raw_payload' => $orderData,
                'last_synced_at' => now(),
            ]);

            if (! $order->exists) {
                $order->internal_stock_status = KaspiOrderInternalStatus::Observed;
            }

            $order->save();

            $eventType = $wasNew ? 'ORDER_CREATED' : 'ORDER_UPDATED';
            KaspiStockEventLog::record($eventType, kaspiOrderId: $order->id, metadata: [
                'kaspi_status' => $order->kaspi_status,
                'kaspi_state' => $order->kaspi_state,
            ]);

            // Sync items
            [$skuMatched, $skuUnmatched] = $this->syncItems($order);

            // Apply state machine
            $this->applyStateMachine($order);

            return ['is_new' => $wasNew, 'sku_matched' => $skuMatched, 'sku_unmatched' => $skuUnmatched];
        });
    }

    private function syncItems(KaspiOrder $order): array
    {
        $skuMatched = $skuUnmatched = 0;
        $orderId = $order->kaspi_order_id;

        try {
            $entries = $this->client->getOrderEntries($orderId);
        } catch (Throwable $e) {
            report($e);
            return [0, 0];
        }

        foreach ($entries as $entry) {
            $entryId = $entry['id'] ?? null;
            $entryAttrs = $entry['attributes'] ?? [];

            if (! $entryId) {
                continue;
            }

            // Resolve merchant SKU
            $merchantSku = $this->resolveMerchantSku($entry);
            $product = $merchantSku ? $this->matchProduct($merchantSku) : null;

            $matchStatus = $product ? 'matched' : 'unmatched';

            $item = KaspiOrderItem::where('kaspi_entry_id', $entryId)->first()
                ?? KaspiOrderItem::where('kaspi_order_id', $order->id)
                    ->where('merchant_sku', $merchantSku)
                    ->first()
                ?? new KaspiOrderItem(['kaspi_order_id' => $order->id]);

            $item->fill([
                'kaspi_order_id' => $order->id,
                'kaspi_entry_id' => $entryId,
                'product_id' => $product?->id,
                'merchant_sku' => $merchantSku,
                'qty' => (int) ($entryAttrs['quantity'] ?? 1),
                'unit_price' => $entryAttrs['basePrice'] ?? null,
                'total_price' => $entryAttrs['totalPrice'] ?? null,
                'sku_match_status' => $matchStatus,
                'raw_payload' => $entry,
            ]);

            $item->save();

            if ($matchStatus === 'matched') {
                $skuMatched++;
                KaspiStockEventLog::record('SKU_MATCHED', kaspiOrderId: $order->id, kaspiOrderItemId: $item->id, sku: $merchantSku, productId: $product->id);
            } else {
                $skuUnmatched++;
                KaspiStockEventLog::record('SKU_UNMATCHED', kaspiOrderId: $order->id, kaspiOrderItemId: $item->id, sku: $merchantSku);
            }
        }

        return [$skuMatched, $skuUnmatched];
    }

    private function applyStateMachine(KaspiOrder $order): void
    {
        $status = $order->kaspi_status;
        $currentInternal = $order->internal_stock_status;

        // Cancelled orders
        if (in_array($status, ['CANCELLED', 'CANCELLING'], true)) {
            if ($order->isHandoffConfirmed()) {
                $this->reservationEngine->cancelAfterHandoff($order);
            } elseif ($this->handoffDetector->shouldApplyHandoff($order)) {
                // Already handed off but flag was just enabled — treat as post-handoff cancel
                $this->reservationEngine->cancelAfterHandoff($order);
            } else {
                $this->reservationEngine->cancelBeforeHandoff($order);
            }
            return;
        }

        // Detect handoff candidate
        if ($this->handoffDetector->isHandoffCandidate($order)) {
            if ($this->handoffDetector->shouldApplyHandoff($order)) {
                $handoffAt = $this->handoffDetector->resolveHandoffAt($order);
                if ($handoffAt && $currentInternal === KaspiOrderInternalStatus::Reserved) {
                    $this->reservationEngine->applyHandoff($order, $handoffAt);
                    KaspiStockEventLog::record('HANDOFF_CONFIRMED', kaspiOrderId: $order->id, metadata: ['handoff_at' => $handoffAt->toIso8601String()]);
                }
            } elseif ($currentInternal === KaspiOrderInternalStatus::Reserved) {
                $this->reservationEngine->markHandoffCandidate($order);
            }
            return;
        }

        // ASSEMBLE: stays RESERVED
        if ($status === 'ASSEMBLE') {
            KaspiStockEventLog::record('ORDER_ASSEMBLED', kaspiOrderId: $order->id, metadata: [
                'waybill' => $order->waybill,
                'waybill_number' => $order->waybill_number,
            ]);
        }

        // Activate reservation for new/accepted orders
        if (in_array($status, ['APPROVED_BY_BANK', 'ACCEPTED_BY_MERCHANT', 'ASSEMBLE'], true)) {
            if (! $order->baseline_ignored) {
                if ($this->reservationEngine->isObserveMode()) {
                    if ($currentInternal === KaspiOrderInternalStatus::Observed) {
                        KaspiStockEventLog::record('ORDER_OBSERVED', kaspiOrderId: $order->id);
                    }
                } else {
                    $this->reservationEngine->reserve($order);
                }
            }
        }
    }

    private function resolveMerchantSku(array $entry): ?string
    {
        // Try to get from product relationship
        $entryId = $entry['id'] ?? null;
        if (! $entryId) {
            return null;
        }

        try {
            $product = $this->client->getProductForEntry($entryId);
            $masterProductId = $product['id'] ?? null;

            if ($masterProductId) {
                $merchantProduct = $this->client->getMerchantProduct($masterProductId);
                $code = $merchantProduct['attributes']['code'] ?? null;
                if ($code) {
                    return (string) $code;
                }
            }
        } catch (Throwable) {
            // SKU resolution failed — item will be unmatched
        }

        return null;
    }

    private function matchProduct(string $merchantSku): ?Product
    {
        $normalised = Str::upper(trim($merchantSku));

        if (array_key_exists($normalised, $this->skuCache)) {
            return $this->skuCache[$normalised];
        }

        $product = Product::where('kaspi_merchant_sku', $merchantSku)->first()
            ?? Product::where('kaspi_merchant_sku', $normalised)->first()
            ?? Product::where('sku', $merchantSku)->first()
            ?? Product::where('sku', $normalised)->first();

        $this->skuCache[$normalised] = $product;

        return $product;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        // Kaspi uses milliseconds
        if (is_numeric($value) && $value > 1_000_000_000_000) {
            return Carbon::createFromTimestampMs((int) $value);
        }

        return null;
    }

    private function skippedResult(string $reason): array
    {
        return [
            'successful' => true,
            'skipped' => true,
            'message' => $reason,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
        ];
    }
}

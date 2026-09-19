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

        $lookbackDays = (int) config('services.kaspi.orders_lookback_days', 14);
        $fromDate = isset($options['from']) ? Carbon::parse($options['from']) : Carbon::now()->subDays($lookbackDays);
        $toDate   = isset($options['to'])   ? Carbon::parse($options['to'])   : Carbon::now();

        $singleOrderCode = $options['order'] ?? null;
        $isObserve = $this->reservationEngine->isObserveMode();
        $mode = $isObserve ? 'observe' : 'active';

        $created = $updated = $skuMatched = $skuUnmatched = $errors = 0;
        $processed = $entriesTotal = $handoffCandidates = $pagesCount = 0;

        try {
            if ($singleOrderCode) {
                $pages = $this->client->getAllOrders(code: $singleOrderCode);
            } else {
                $pages = $this->client->getAllOrders(
                    state: 'KASPI_DELIVERY',
                    fromMs: $fromDate->getTimestampMs(),
                    toMs: $toDate->getTimestampMs(),
                );
            }

            foreach ($pages as $batch) {
                $pagesCount++;

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

                        $skuMatched    += $result['sku_matched'];
                        $skuUnmatched  += $result['sku_unmatched'];
                        $entriesTotal  += $result['entries_count'];
                        $handoffCandidates += $result['is_handoff_candidate'] ? 1 : 0;
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
                'message'    => 'Sync failed: ' . $e->getMessage(),
                'mode'       => $mode,
                'processed'  => $processed,
                'created'    => $created,
                'updated'    => $updated,
                'errors'     => $errors,
            ];
        }

        return [
            'successful'         => true,
            'mode'               => $mode,
            'dry_run'            => $dryRun,
            'processed'          => $processed,
            'pages'              => $pagesCount,
            'entries'            => $entriesTotal,
            'created'            => $created,
            'updated'            => $updated,
            'sku_matched'        => $skuMatched,
            'sku_unmatched'      => $skuUnmatched,
            'handoff_candidates' => $handoffCandidates,
            'errors'             => $errors,
        ];
    }

    private function processOrder(array $orderData, bool $dryRun): array
    {
        $attrs = $orderData['attributes'] ?? [];
        $kaspiOrderId = $orderData['id'] ?? null;

        if (! $kaspiOrderId) {
            throw new \RuntimeException('Order data missing id.');
        }

        // Extract kaspiDelivery nested block — canonical source for handoff fields
        $kaspiDelivery = $attrs['kaspiDelivery'] ?? [];
        $courierDate = $this->parseTimestamp($kaspiDelivery['courierTransmissionDate'] ?? null);

        // Handoff candidate purely from API data (no DB needed)
        $isHandoffCandidate = ($attrs['state'] ?? null) === 'KASPI_DELIVERY'
            && $courierDate !== null;

        $isNew = ! KaspiOrder::where('kaspi_order_id', $kaspiOrderId)->exists();

        // Always fetch entries — required for dry-run summary and real sync
        $entryData = $this->readEntries($kaspiOrderId);

        if ($dryRun) {
            return [
                'is_new'              => $isNew,
                'sku_matched'         => $entryData['matched'],
                'sku_unmatched'       => $entryData['unmatched'],
                'entries_count'       => $entryData['total'],
                'is_handoff_candidate' => $isHandoffCandidate,
            ];
        }

        return DB::transaction(function () use (
            $orderData, $attrs, $kaspiDelivery, $kaspiOrderId, $courierDate, $isHandoffCandidate, $entryData
        ) {
            $order = KaspiOrder::firstOrNew(['kaspi_order_id' => $kaspiOrderId]);
            $wasNew = ! $order->exists;

            $courierPlanDate = $this->parseTimestamp($kaspiDelivery['courierTransmissionPlanningDate'] ?? null);
            $kaspiCreatedAt  = $this->parseTimestamp($attrs['creationDate'] ?? null);
            $completionDate  = $this->parseTimestamp($attrs['completionDate'] ?? null);

            $order->fill([
                'kaspi_code'                        => $attrs['code'] ?? null,
                'kaspi_status'                      => $attrs['status'] ?? null,
                'kaspi_state'                       => $attrs['state'] ?? null,
                'delivery_type'                     => $attrs['deliveryMode'] ?? ($attrs['state'] ?? null),
                'kaspi_created_at'                  => $kaspiCreatedAt,
                'courier_transmission_planning_date' => $courierPlanDate,
                'courier_transmission_date'          => $courierDate,
                'completed_at'                      => $completionDate,
                'waybill'                           => $kaspiDelivery['waybill'] ?? null,
                'waybill_number'                    => $kaspiDelivery['waybillNumber'] ?? null,
                'raw_payload'                       => $orderData,
                'last_synced_at'                    => now(),
            ]);

            if (! $order->exists) {
                $order->internal_stock_status = KaspiOrderInternalStatus::Observed;
            }

            $order->save();

            $eventType = $wasNew ? 'ORDER_CREATED' : 'ORDER_UPDATED';
            KaspiStockEventLog::record($eventType, kaspiOrderId: $order->id, metadata: [
                'kaspi_status' => $order->kaspi_status,
                'kaspi_state'  => $order->kaspi_state,
            ]);

            [$skuMatched, $skuUnmatched] = $this->persistItems($order, $entryData['entries']);

            $this->applyStateMachine($order);

            return [
                'is_new'               => $wasNew,
                'sku_matched'          => $skuMatched,
                'sku_unmatched'        => $skuUnmatched,
                'entries_count'        => $entryData['total'],
                'is_handoff_candidate' => $isHandoffCandidate,
            ];
        });
    }

    /**
     * Fetch entries from the API and resolve merchant SKUs.
     * Pure read — no DB writes.
     *
     * @return array{entries: array, matched: int, unmatched: int, total: int}
     */
    private function readEntries(string $kaspiOrderId): array
    {
        try {
            $entries = $this->client->getOrderEntries($kaspiOrderId);
        } catch (Throwable $e) {
            report($e);
            return ['entries' => [], 'matched' => 0, 'unmatched' => 0, 'total' => 0];
        }

        $matched = $unmatched = 0;

        foreach ($entries as $entry) {
            $sku = $this->resolveMerchantSku($entry);
            $product = $sku ? $this->matchProduct($sku) : null;

            if ($product !== null) {
                $matched++;
            } else {
                $unmatched++;
            }
        }

        return [
            'entries'  => $entries,
            'matched'  => $matched,
            'unmatched' => $unmatched,
            'total'    => count($entries),
        ];
    }

    /**
     * Write pre-fetched entries to DB as KaspiOrderItems.
     *
     * @return array{0: int, 1: int} [skuMatched, skuUnmatched]
     */
    private function persistItems(KaspiOrder $order, array $entries): array
    {
        $skuMatched = $skuUnmatched = 0;

        foreach ($entries as $entry) {
            $entryId   = $entry['id'] ?? null;
            $entryAttrs = $entry['attributes'] ?? [];

            if (! $entryId) {
                continue;
            }

            $merchantSku = $this->resolveMerchantSku($entry);
            $product     = $merchantSku ? $this->matchProduct($merchantSku) : null;
            $matchStatus = $product ? 'matched' : 'unmatched';

            $item = KaspiOrderItem::where('kaspi_entry_id', $entryId)->first()
                ?? new KaspiOrderItem(['kaspi_order_id' => $order->id]);

            $item->fill([
                'kaspi_order_id'   => $order->id,
                'kaspi_entry_id'   => $entryId,
                'product_id'       => $product?->id,
                'merchant_sku'     => $merchantSku,
                'qty'              => (int) ($entryAttrs['quantity'] ?? 1),
                'unit_price'       => $entryAttrs['basePrice'] ?? null,
                'total_price'      => $entryAttrs['totalPrice'] ?? null,
                'sku_match_status' => $matchStatus,
                'raw_payload'      => $entry,
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
                    KaspiStockEventLog::record('HANDOFF_CONFIRMED', kaspiOrderId: $order->id, metadata: [
                        'handoff_at' => $handoffAt->toIso8601String(),
                    ]);
                }
            } elseif ($currentInternal === KaspiOrderInternalStatus::Reserved) {
                $this->reservationEngine->markHandoffCandidate($order);
            }

            return;
        }

        // ASSEMBLE: stays RESERVED
        if ($status === 'ASSEMBLE') {
            KaspiStockEventLog::record('ORDER_ASSEMBLED', kaspiOrderId: $order->id, metadata: [
                'waybill'        => $order->waybill,
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

    /**
     * Resolve merchant SKU for an order entry.
     *
     * Primary: attributes.offer.code (canonical, no extra API call needed)
     * Fallback: masterproduct → merchantProduct API chain (exceptional case only)
     */
    private function resolveMerchantSku(array $entry): ?string
    {
        // Primary source: offer.code is the merchant SKU
        $offerCode = $entry['attributes']['offer']['code'] ?? null;
        if ($offerCode !== null && $offerCode !== '') {
            return (string) $offerCode;
        }

        // Fallback: masterproduct chain (only when offer.code is absent)
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
            // Fallback failed — item will be unmatched
        }

        return null;
    }

    /**
     * Match a merchant SKU to a local product.
     *
     * Canonical order:
     *   1. products.sku          — primary merchant SKU field
     *   2. products.kaspi_merchant_sku — fallback for variants/aliases
     */
    private function matchProduct(string $merchantSku): ?Product
    {
        $key = trim($merchantSku);

        if (array_key_exists($key, $this->skuCache)) {
            return $this->skuCache[$key];
        }

        $product = Product::where('sku', $merchantSku)->first()
            ?? Product::where('sku', $key)->first()
            ?? Product::where('kaspi_merchant_sku', $merchantSku)->first()
            ?? Product::where('kaspi_merchant_sku', $key)->first();

        $this->skuCache[$key] = $product;

        return $product;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        // Kaspi timestamps are Unix milliseconds
        if (is_numeric($value) && $value > 1_000_000_000_000) {
            return Carbon::createFromTimestampMs((int) $value);
        }

        return null;
    }

    private function skippedResult(string $reason): array
    {
        return [
            'successful'         => true,
            'skipped'            => true,
            'message'            => $reason,
            'processed'          => 0,
            'pages'              => 0,
            'entries'            => 0,
            'created'            => 0,
            'updated'            => 0,
            'handoff_candidates' => 0,
            'errors'             => 0,
        ];
    }
}

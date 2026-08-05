<?php

namespace App\Services\Kaspi;

use App\Models\KaspiEnrichmentTask;
use App\Models\KaspiImportReceipt;
use App\Models\Product;
use App\Support\ContentScore;
use App\Support\KaspiBridgeSku;
use App\Support\MeaningfulContent;
use App\Support\Utf8Sanitizer;
use Illuminate\Support\Facades\DB;

class KaspiProductionImportService
{
    public function __construct(
        private readonly KaspiDraftPublisher $publisher,
        private readonly KaspiProductionPayloadValidator $validator,
    ) {
    }

    /**
     * @return array{http_status: int, body: array<string, mixed>}
     */
    public function import(array $payload, int $rawBytes = 0): array
    {
        $payload = $this->validator->validate($payload, $rawBytes);
        $requestId = (string) $payload['request_id'];
        $contentHash = $this->validator->contentHash($payload);
        $sku = (string) $payload['sku'];
        $normalizedSku = KaspiBridgeSku::normalize($sku);

        $existingReceipt = KaspiImportReceipt::query()->where('request_id', $requestId)->first();
        if ($existingReceipt) {
            return [
                'http_status' => $this->httpStatusForReceipt($existingReceipt),
                'body' => (array) ($existingReceipt->result_summary ?: []),
            ];
        }

        $sameContent = KaspiImportReceipt::query()
            ->where('normalized_sku', $normalizedSku)
            ->where('content_hash', $contentHash)
            ->whereIn('status', ['imported', 'unchanged'])
            ->latest('id')
            ->first();

        if ($sameContent) {
            $body = [
                'ok' => true,
                'status' => 'unchanged',
                'request_id' => $requestId,
                'sku' => $sku,
            ];
            $this->storeReceipt($payload, $contentHash, $normalizedSku, 'unchanged', $body);

            return ['http_status' => 200, 'body' => $body];
        }

        $products = $this->matchingProducts($normalizedSku);
        if ($products->count() === 0) {
            $body = ['ok' => false, 'error' => 'product_not_found', 'sku' => $sku];
            $this->storeReceipt($payload, $contentHash, $normalizedSku, 'failed', $body, 'product_not_found');

            return ['http_status' => 404, 'body' => $body];
        }

        if ($products->count() > 1) {
            $body = ['ok' => false, 'error' => 'duplicate_sku_conflict', 'sku' => $sku];
            $this->storeReceipt($payload, $contentHash, $normalizedSku, 'failed', $body, 'duplicate_sku_conflict');

            return ['http_status' => 409, 'body' => $body];
        }

        $product = $products->first();
        $manualBlock = $this->manualBlockReason($product, $payload);
        if ($manualBlock !== null) {
            $body = ['ok' => false, 'error' => 'manual_content_protected', 'sku' => $sku, 'reason' => $manualBlock];
            $this->storeReceipt($payload, $contentHash, $normalizedSku, 'blocked', $body, 'manual_content_protected');

            return ['http_status' => 409, 'body' => $body];
        }

        return DB::transaction(function () use ($payload, $contentHash, $normalizedSku, $product, $requestId, $sku): array {
            $product->refresh()->load(['images', 'attributes']);
            $before = $this->protectedProductSnapshot($product);
            $task = $this->taskFromPayload($product, $payload);
            $applyPhoto = ! ContentScore::hasPhoto($product);
            $applyDescription = MeaningfulContent::descriptionIsEmpty($product->description);
            $applyAttributes = ! $product->attributes()->exists();
            $result = $this->publisher->publish($task, [
                'dry_run' => false,
                'apply_photo' => $applyPhoto,
                'apply_description' => $applyDescription,
                'apply_attributes' => $applyAttributes,
                'force_photo' => false,
                'force_description' => false,
                'force_attributes' => false,
                'replace_kaspi_attributes' => false,
                'strict_image_security' => true,
                'image_batch_id' => $requestId,
            ]);

            $product->refresh();
            $this->assertProtectedFieldsUnchanged($before, $product);

            if (($result['errors'] ?? []) !== []) {
                $body = [
                    'ok' => false,
                    'error' => 'import_failed',
                    'sku' => $sku,
                    'result' => $this->safeResult($result),
                ];
                $this->storeReceipt($payload, $contentHash, $normalizedSku, 'failed', $body, 'import_failed');

                return ['http_status' => 422, 'body' => $body];
            }

            $summary = [
                'name_updated' => false,
                'description_updated' => (bool) ($result['description']['added'] ?? 0),
                'attributes_updated' => (int) ($result['attributes']['added'] ?? 0),
                'images_imported' => (int) ($result['photo']['added'] ?? 0),
            ];
            $status = array_sum([
                (int) $summary['description_updated'],
                $summary['attributes_updated'],
                $summary['images_imported'],
            ]) > 0 ? 'imported' : 'unchanged';

            $body = [
                'ok' => true,
                'status' => $status,
                'request_id' => $requestId,
                'sku' => $sku,
                'result' => $summary,
            ];
            $this->storeReceipt($payload, $contentHash, $normalizedSku, $status, $body);

            return ['http_status' => 200, 'body' => $body];
        });
    }

    private function taskFromPayload(Product $product, array $payload): KaspiEnrichmentTask
    {
        $content = (array) $payload['content'];
        $images = collect((array) ($content['images'] ?? []))
            ->sortBy(fn (array $image): int => (int) ($image['position'] ?? 0))
            ->pluck('url')
            ->filter()
            ->values()
            ->all();

        $attributes = array_values(array_filter((array) ($content['attributes'] ?? []), fn (array $attribute): bool => filled($attribute['name'] ?? null) && filled($attribute['value'] ?? null)));
        $rawPayload = [
            'url' => $payload['kaspi_url'],
            'name' => $content['name'] ?? null,
            'description' => $content['description'] ?? null,
            'images' => $images,
            'attributes' => $attributes,
            'cleaned' => [
                'images' => $images,
                'description' => $content['description'] ?? null,
                'attributes' => $attributes,
            ],
            'source' => $payload['source'],
        ];

        $product->update(['kaspi_product_url' => $payload['kaspi_url']]);

        return KaspiEnrichmentTask::query()->updateOrCreate([
            'product_id' => $product->id,
            'kaspi_merchant_sku' => $product->sku,
        ], [
            'kaspi_product_url' => $payload['kaspi_url'],
            'missing_photo' => ! ContentScore::hasPhoto($product),
            'missing_description' => MeaningfulContent::descriptionIsEmpty($product->description),
            'missing_attributes' => ! $product->attributes()->exists(),
            'status' => 'draft',
            'source' => 'production_bridge',
            'parsed_title' => ['value' => $content['name'] ?? null],
            'parsed_images' => $images,
            'parsed_description' => $content['description'] ?? null,
            'parsed_attributes' => $attributes,
            'raw_payload' => $rawPayload,
            'finished_at' => now(),
            'error' => null,
        ]);
    }

    private function matchingProducts(string $normalizedSku)
    {
        return Product::query()
            ->whereNull('deleted_at')
            ->get()
            ->filter(function (Product $product) use ($normalizedSku): bool {
                foreach ([$product->sku, $product->kaspi_merchant_sku, $product->paloma_sku] as $sku) {
                    if (KaspiBridgeSku::normalize($sku) === $normalizedSku) {
                        return true;
                    }
                }

                return false;
            })
            ->unique('id')
            ->values();
    }

    private function manualBlockReason(Product $product, array $payload): ?string
    {
        if ((bool) $product->auto_content_locked) {
            return 'auto_content_locked';
        }

        $content = (array) $payload['content'];
        $incomingImages = (array) ($content['images'] ?? []);
        $incomingDescription = $content['description'] ?? null;
        $incomingAttributes = (array) ($content['attributes'] ?? []);
        $hasPhoto = ContentScore::hasPhoto($product);
        $hasDescription = MeaningfulContent::hasDescription($product->description);
        $hasAttributes = $product->attributes()->exists();
        $hasImportableMissingField = ($incomingImages !== [] && ! $hasPhoto)
            || (MeaningfulContent::hasDescription($incomingDescription) && ! $hasDescription)
            || ($incomingAttributes !== [] && ! $hasAttributes && ! (bool) $product->attributes_are_manual);

        if ($hasImportableMissingField) {
            return null;
        }

        if ($hasPhoto && (bool) $product->photos_are_manual && $incomingImages !== []) {
            return 'photos_are_manual';
        }

        if ($hasDescription && (bool) $product->description_is_manual && MeaningfulContent::hasDescription($incomingDescription)) {
            return 'description_is_manual';
        }

        if ($hasAttributes && (bool) $product->attributes_are_manual && $incomingAttributes !== []) {
            return 'attributes_are_manual';
        }

        return null;
    }

    private function storeReceipt(array $payload, string $contentHash, string $normalizedSku, string $status, array $body, ?string $errorCode = null): KaspiImportReceipt
    {
        return KaspiImportReceipt::query()->create([
            'request_id' => $payload['request_id'],
            'sku' => $payload['sku'],
            'normalized_sku' => $normalizedSku,
            'content_hash' => $contentHash,
            'received_at' => now(),
            'collected_at' => $payload['collected_at'] ?? null,
            'status' => $status,
            'result_summary' => $body,
            'error_code' => $errorCode,
        ]);
    }

    private function httpStatusForReceipt(KaspiImportReceipt $receipt): int
    {
        return match ($receipt->error_code) {
            'product_not_found' => 404,
            'manual_content_protected',
            'duplicate_sku_conflict' => 409,
            'import_failed' => 422,
            default => 200,
        };
    }

    private function protectedProductSnapshot(Product $product): array
    {
        return $product->only([
            'price',
            'quantity',
            'stock_quantity',
            'availability',
            'category_id',
            'brand_id',
            'sku',
            'paloma_id',
            'paloma_sku',
            'price_source',
            'stock_source',
        ]);
    }

    private function assertProtectedFieldsUnchanged(array $before, Product $product): void
    {
        foreach ($before as $field => $value) {
            if ($product->{$field} != $value) {
                throw new \RuntimeException('protected_field_changed_'.$field);
            }
        }
    }

    private function safeResult(array $result): array
    {
        $result['errors'] = array_map(
            fn (mixed $error): string => Utf8Sanitizer::forDb((string) $error, 300),
            (array) ($result['errors'] ?? []),
        );

        return $result;
    }
}

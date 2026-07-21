<?php

namespace App\Services\Catalog;

use App\Models\CatalogEnrichmentTask;
use App\Models\ContentChangeLog;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;

class EnrichmentPublisher
{
    public function publish(CatalogEnrichmentTask $task): bool
    {
        if ($task->status !== 'approved' || ! $task->product) {
            return false;
        }

        return DB::transaction(function () use ($task): bool {
            $product = $task->product()->lockForUpdate()->firstOrFail();
            $payload = $task->suggested_payload ?: [];
            $old = [];
            $new = [];

            match ($task->task_type) {
                'image' => [$old, $new] = $this->publishImage($task, $payload),
                'description' => [$old, $new] = $this->publishDescription($product, $payload),
                'seo', 'seo_title', 'seo_description' => [$old, $new] = $this->publishSeo($product, $payload),
                'brand' => [$old, $new] = $this->publishBrand($product, $payload),
                'category' => [$old, $new] = $this->publishCategory($product, $payload),
                default => [$old, $new] = [[], []],
            };

            if ($new === []) {
                $task->update(['status' => 'failed', 'reason' => trim(($task->reason ?: '').' No publishable payload.')]);

                return false;
            }

            ContentChangeLog::query()->create([
                'product_id' => $product->id,
                'enrichment_task_id' => $task->id,
                'type' => $task->task_type,
                'old_payload' => $old,
                'new_payload' => $new,
                'user_id' => auth()->id(),
                'created_at' => now(),
            ]);

            $task->update([
                'status' => 'published',
                'published_at' => now(),
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            return true;
        });
    }

    private function publishImage(CatalogEnrichmentTask $task, array $payload): array
    {
        $product = $task->product;
        $image = $payload['images'][0] ?? $payload;
        $path = $image['path'] ?? null;

        if (blank($path)) {
            return [[], []];
        }

        $old = ['primary_image' => $product->primary_image];

        $productImage = ProductImage::query()->firstOrCreate([
            'product_id' => $product->id,
            'path' => $path,
        ], [
            'original_path' => $image['original_path'] ?? $path,
            'alt' => $image['alt'] ?? $product->display_name,
            'title' => $image['title'] ?? $product->display_name,
            'role' => 'primary',
            'sort_order' => 0,
            'source' => $image['source'] ?? 'manual',
        ]);

        $productImage->update(['is_primary' => true, 'role' => 'primary']);
        $product->refresh();

        return [$old, ['primary_image' => $product->primary_image, 'product_image_id' => $productImage->id]];
    }

    private function publishDescription($product, array $payload): array
    {
        $updates = array_filter([
            'description' => $payload['description'] ?? null,
            'short_description' => $payload['short_description'] ?? null,
        ], fn ($value): bool => filled($value));

        if ($updates === []) {
            return [[], []];
        }

        $old = $product->only(array_keys($updates));
        $product->update($updates);

        return [$old, $updates];
    }

    private function publishSeo($product, array $payload): array
    {
        $updates = [];

        if (filled($payload['seo_title'] ?? null)) {
            $updates['meta_title'] = $payload['seo_title'];
        }

        if (filled($payload['meta_description'] ?? null)) {
            $updates['meta_description'] = $payload['meta_description'];
        }

        if ($updates === []) {
            return [[], []];
        }

        $old = $product->only(array_keys($updates));
        $product->update($updates);

        return [$old, $updates];
    }

    private function publishBrand($product, array $payload): array
    {
        if (blank($payload['brand_id'] ?? null)) {
            return [[], []];
        }

        $old = ['brand_id' => $product->brand_id];
        $new = ['brand_id' => (int) $payload['brand_id']];
        $product->update($new);

        return [$old, $new];
    }

    private function publishCategory($product, array $payload): array
    {
        return [[], []];
    }
}

<?php

namespace App\Services\Kaspi;

use App\Models\KaspiEnrichmentTask;
use App\Models\KaspiPublishLog;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductImage;
use App\Services\Catalog\CatalogDescriptionFallbackService;
use App\Support\ContentScore;
use App\Support\Utf8Sanitizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

class KaspiDraftPublisher
{
    public function publish(KaspiEnrichmentTask $task, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $applyPhoto = (bool) ($options['apply_photo'] ?? true);
        $applyDescription = (bool) ($options['apply_description'] ?? true);
        $applyAttributes = (bool) ($options['apply_attributes'] ?? true);
        $replaceKaspiAttributes = (bool) ($options['replace_kaspi_attributes'] ?? false);
        $forceAttributes = (bool) ($options['force_attributes'] ?? false);
        $forcePhoto = (bool) ($options['force_photo'] ?? false);
        $forceDescription = (bool) ($options['force_description'] ?? false);
        $product = $task->product()->with(['images', 'attributes'])->firstOrFail();
        $plan = $this->plan($product, $task, $applyPhoto, $applyDescription, $applyAttributes, $forceAttributes, $forcePhoto, $forceDescription);
        $result = [
            'photo' => $plan['photo'],
            'description' => $plan['description'],
            'attributes' => $plan['attributes'],
            'skipped_service_attributes' => count((array) data_get($task->raw_payload, 'debug.excluded_attributes', [])),
            'rejected_images' => count((array) data_get($task->raw_payload, 'debug.rejected_images', [])),
            'errors' => [],
        ];

        if ($dryRun) {
            return $result;
        }

        try {
            if ($plan['photo']['will_apply']) {
                $result['photo']['added'] = $this->publishImages($product, $this->taskImages($task), $forcePhoto);
            }

            if ($plan['description']['will_apply']) {
                $product->update(['description' => Utf8Sanitizer::forDb($this->descriptionForPublish($product, $task), 65000)]);
                $result['description']['added'] = 1;
            }

            if ($plan['attributes']['will_apply']) {
                if ($replaceKaspiAttributes || $forceAttributes) {
                    $product->attributes()->where('group_name', 'Kaspi')->delete();
                }

                $result['attributes']['added'] = $this->publishAttributes($product, $this->taskAttributes($task));
            }

            $task->update(['status' => 'published', 'finished_at' => now(), 'error' => null]);
            $this->log($product, $task, $result, 'success');
        } catch (Throwable $exception) {
            $safeError = Utf8Sanitizer::errorForDb($exception);
            $task->update(['status' => 'failed', 'error' => $safeError, 'finished_at' => now()]);
            $result['errors'][] = $safeError;
            $this->log($product, $task, $result, 'error', $safeError);
        }

        return $result;
    }

    public function plan(Product $product, KaspiEnrichmentTask $task, bool $applyPhoto = true, bool $applyDescription = true, bool $applyAttributes = true, bool $forceAttributes = false, bool $forcePhoto = false, bool $forceDescription = false): array
    {
        $images = $this->taskImages($task);
        $description = $this->descriptionForPublish($product, $task);
        $attributes = $this->taskAttributes($task);
        $hasAttributes = $product->attributes()->count() > 0;
        $hasPhoto = ContentScore::hasPhoto($product);
        $hasKaspiPhoto = $product->images()->where('source', 'kaspi')->exists();
        $hasDescription = filled($product->description);
        $locked = (bool) $product->auto_content_locked;
        $photosProtected = $locked || (bool) $product->photos_are_manual;
        $descriptionProtected = $locked || (bool) $product->description_is_manual;
        $attributesProtected = $locked || (bool) $product->attributes_are_manual;

        return [
            'photo' => [
                'will_apply' => $applyPhoto && $images !== [],
                'reason' => ($images === [])
                    ? 'В Kaspi draft нет фото.'
                    : ($forcePhoto
                        ? ($photosProtected ? 'Фото защищены; force заменит только при явном запуске.' : 'Фото Kaspi заменят текущие фото.')
                        : ($hasKaspiPhoto ? 'Будут добавлены недостающие фото из Kaspi.' : ($hasPhoto ? 'Будет добавлена Kaspi gallery без замены текущих фото.' : 'Будут добавлены фото из Kaspi.'))),
                'count' => count($images),
            ],
            'description' => [
                'will_apply' => $applyDescription && filled($description) && ($forceDescription || (! $hasDescription && ! $descriptionProtected)),
                'reason' => blank($description)
                    ? 'В Kaspi draft нет чистого описания и fallback не применим.'
                    : ($locked ? 'Auto content locked.'
                        : ($product->description_is_manual ? 'Description is marked as manual.'
                            : ($forceDescription ? 'Описание Kaspi заменит текущее описание.' : ($hasDescription ? 'На сайте уже есть описание.' : 'Будет добавлено описание.')))),
                'count' => filled($description) ? 1 : 0,
            ],
            'attributes' => [
                'will_apply' => $applyAttributes && $attributes !== [] && ($forceAttributes || (! $hasAttributes && ! $attributesProtected)),
                'reason' => $locked ? 'Auto content locked.'
                    : ($product->attributes_are_manual ? 'Specifications are marked as manual.'
                        : ($hasAttributes
                            ? 'На сайте уже есть характеристики.'
                            : ($attributes === [] ? 'В Kaspi draft нет чистых характеристик.' : 'Будут добавлены характеристики.'))),
                'count' => count($attributes),
            ],
        ];
    }

    private function publishImages(Product $product, array $images, bool $force = false): int
    {
        $hadPhotoBeforeImport = ContentScore::hasPhoto($product);

        if ($force) {
            // Delete all existing product images so Kaspi photos become the only source.
            // The ProductImage::booted hook automatically updates products.primary_image.
            $product->images()->delete();
            $product->unsetRelation('images');
            $product->unsetRelation('primaryImage');
            $hadPhotoBeforeImport = false;
        }

        $seen = [];
        foreach ($product->images()->pluck('original_path')->filter() as $originalPath) {
            $seen[$this->imageKey((string) $originalPath)] = true;
        }

        $created = 0;
        foreach (array_values($images) as $url) {
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                continue;
            }

            $key = $this->imageKey($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $path = $this->downloadImage($product, $url);
            if (! $path) {
                continue;
            }

            $displayName = Utf8Sanitizer::forDb($product->display_name, 255);
            ProductImage::query()->create([
                'product_id' => $product->id,
                'path' => $path,
                'original_path' => Utf8Sanitizer::forDb($url, 500),
                'original_name' => Utf8Sanitizer::forDb(basename(parse_url($url, PHP_URL_PATH) ?: $path), 255),
                'alt' => $displayName,
                'title' => $displayName,
                'role' => (! $hadPhotoBeforeImport && $created === 0) ? 'primary' : 'gallery',
                'sort_order' => $created,
                'is_primary' => ! $hadPhotoBeforeImport && $created === 0,
                'source' => 'kaspi',
            ]);

            $created++;
        }

        return $created;
    }

    private function publishAttributes(Product $product, array $attributes): int
    {
        $changed = 0;
        foreach (array_values($attributes) as $index => $attribute) {
            if (blank($attribute['name'] ?? null) || blank($attribute['value'] ?? null)) {
                continue;
            }

            $name = Utf8Sanitizer::forDb(trim((string) $attribute['name']), 191);
            $value = Utf8Sanitizer::forDb(trim((string) $attribute['value']), 600);
            $existing = $product->attributes()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($existing) {
                $existing->update([
                    'value' => $value,
                    'sort_order' => $existing->sort_order ?? $index,
                ]);
                $changed++;

                continue;
            }

            ProductAttribute::query()->create([
                'product_id' => $product->id,
                'group_name' => 'Kaspi',
                'name' => $name,
                'value' => $value,
                'sort_order' => $index,
                'is_filterable' => false,
            ]);

            $changed++;
        }

        return $changed;
    }

    private function taskImages(KaspiEnrichmentTask $task): array
    {
        return array_values(array_filter((array) data_get($task->raw_payload, 'cleaned.images', $task->parsed_images ?: [])));
    }

    private function taskDescription(KaspiEnrichmentTask $task): ?string
    {
        $description = data_get($task->raw_payload, 'cleaned.description', $task->parsed_description);

        return filled($description) ? Utf8Sanitizer::forDb((string) $description, 65000) : null;
    }

    private function descriptionForPublish(Product $product, KaspiEnrichmentTask $task): ?string
    {
        $description = $this->taskDescription($task);

        if (filled($description)) {
            return $description;
        }

        return app(CatalogDescriptionFallbackService::class)->generate($product, $this->taskAttributes($task));
    }

    private function taskAttributes(KaspiEnrichmentTask $task): array
    {
        return array_values(array_filter(
            (array) data_get($task->raw_payload, 'cleaned.attributes', $task->parsed_attributes ?: []),
            fn ($attribute): bool => filled($attribute['name'] ?? null) && filled($attribute['value'] ?? null)
        ));
    }

    private function downloadImage(Product $product, string $url): ?string
    {
        $response = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
                    ->get($url);

                if ($response->successful() && $response->body() !== '') {
                    break;
                }

                $lastError = 'HTTP '.($response?->status() ?? 'no_response');
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            if ($attempt < 3) {
                usleep(500_000 * $attempt);
            }
        }

        if (! $response || ! $response->successful() || $response->body() === '') {
            report(new \RuntimeException(sprintf(
                'Kaspi image download failed after retries. product_id=%d sku=%s url=%s error=%s',
                $product->id,
                (string) $product->sku,
                $url,
                $lastError ?: 'empty_response',
            )));

            return null;
        }

        $extension = $this->imageExtension($url, (string) $response->header('Content-Type'));
        $path = 'products/kaspi/'.$product->id.'/'.sha1($url).'.'.$extension;
        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    private function imageExtension(string $url, string $contentType): string
    {
        return match (true) {
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            default => pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg',
        };
    }

    private function imageKey(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $path = preg_replace('#/(small|preview|thumbnail|thumb|gallery-small|gallery-medium|large)/#i', '/', $path) ?: $path;

        return mb_strtolower($path);
    }

    private function log(Product $product, KaspiEnrichmentTask $task, array $fields, string $status, ?string $error = null): void
    {
        KaspiPublishLog::query()->create([
            'product_id' => $product->id,
            'kaspi_enrichment_task_id' => $task->id,
            'user_id' => Auth::id(),
            'actor' => Auth::user()?->email ?: 'system',
            'published_fields' => $fields,
            'dry_run' => false,
            'status' => $status,
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}

<?php

namespace App\Services\Catalog;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use App\Support\Utf8Sanitizer;
use Illuminate\Support\Collection;

class EnrichmentTaskBuilder
{
    public const ACTIVE_STATUSES = ['pending', 'draft', 'approved'];

    public function __construct(private readonly ProductProblemDetector $detector)
    {
    }

    public function createMissingTasksForProduct(Product $product): array
    {
        $tasks = [];

        foreach ($this->detector->detect($product) as $problem) {
            $task = match ($problem) {
                ProductProblemDetector::MISSING_IMAGE => $this->createImageTask($product),
                ProductProblemDetector::MISSING_DESCRIPTION => $this->createDescriptionTask($product),
                ProductProblemDetector::MISSING_SEO => $this->createSeoTask($product),
                ProductProblemDetector::MISSING_BRAND => $this->createBrandTask($product),
                ProductProblemDetector::MISSING_CATEGORY => $this->createCategoryTask($product),
                default => null,
            };

            foreach (array_filter(is_array($task) ? $task : [$task]) as $item) {
                $tasks[] = $item;
            }
        }

        return $tasks;
    }

    public function createTasksForProducts(Collection $products): array
    {
        return $products
            ->flatMap(fn (Product $product): array => $this->createMissingTasksForProduct($product))
            ->all();
    }

    public function createImageTask(Product $product): ?CatalogEnrichmentTask
    {
        return $this->createTask($product, 'image', 'Missing product image.');
    }

    public function createDescriptionTask(Product $product): ?CatalogEnrichmentTask
    {
        return $this->createTask($product, 'description', 'Missing product description.');
    }

    public function createSeoTask(Product $product): array
    {
        return [
            $this->createTask($product, 'seo_title', 'Missing SEO title.'),
            $this->createTask($product, 'seo_description', 'Missing SEO description.'),
        ];
    }

    public function createBrandTask(Product $product): ?CatalogEnrichmentTask
    {
        return $this->createTask($product, 'brand', 'Missing product brand.');
    }

    public function createCategoryTask(Product $product): ?CatalogEnrichmentTask
    {
        return $this->createTask($product, 'category', 'Missing product category.');
    }

    public function createTask(Product $product, string $type, string $reason, array $suggestedPayload = [], int $confidence = 0): CatalogEnrichmentTask
    {
        $currentPayload = Utf8Sanitizer::clean($this->currentPayload($product, $type));
        $suggestedPayload = Utf8Sanitizer::clean($suggestedPayload);
        $priority = $this->priorityValue($this->detector->getPriority($product));

        $task = CatalogEnrichmentTask::query()
            ->where('product_id', $product->id)
            ->where('task_type', $type)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->first();

        $values = [
            'source' => $this->normalizeSource($suggestedPayload['source'] ?? ($suggestedPayload === [] ? 'manual' : 'rule')),
            'priority' => $priority,
            'confidence' => max($confidence, (int) ($suggestedPayload['confidence'] ?? 0)),
            'reason' => Utf8Sanitizer::cleanString($reason),
            'current_value' => Utf8Sanitizer::cleanString($this->currentValue($product, $type)),
            'current_payload' => $currentPayload,
            'payload_json' => Utf8Sanitizer::clean([
                'problem_codes' => $this->detector->detect($product),
                'created_by' => 'phase_13_builder',
            ]),
        ];

        if ($suggestedPayload !== []) {
            $values['suggested_payload'] = $suggestedPayload;
            $values['suggested_value'] = Utf8Sanitizer::cleanString($this->suggestedValue($suggestedPayload));
        }

        if ($task) {
            $task->fill($values);
            $task->updated_at = now();
            $task->save();

            return $task;
        }

        return CatalogEnrichmentTask::query()->create($values + [
            'product_id' => $product->id,
            'task_type' => $type,
            'status' => 'draft',
        ]);
    }

    private function currentPayload(Product $product, string $type): array
    {
        return match ($type) {
            'image' => ['primary_image' => $product->primary_image],
            'description' => [
                'description' => $product->description,
                'short_description' => $product->short_description,
            ],
            'seo_title' => ['meta_title' => $product->meta_title, 'h1' => $product->h1],
            'seo_description' => ['meta_description' => $product->meta_description],
            'brand' => ['brand_id' => $product->brand_id, 'brand_name' => $product->brand?->display_name],
            'category' => ['category_id' => $product->category_id, 'category_name' => $product->category?->display_name],
            default => [],
        };
    }

    private function currentValue(Product $product, string $type): ?string
    {
        return match ($type) {
            'image' => $product->primary_image,
            'description' => $product->description,
            'seo_title' => $product->meta_title,
            'seo_description' => $product->meta_description,
            'brand' => $product->brand?->display_name,
            'category' => $product->category?->display_name,
            default => null,
        };
    }

    private function suggestedValue(array $payload): string
    {
        foreach (['description', 'seo_title', 'meta_description', 'brand_name', 'category_name', 'path', 'url'] as $key) {
            if (filled($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        if (! empty($payload['images'][0]['path'])) {
            return (string) $payload['images'][0]['path'];
        }

        return json_encode(Utf8Sanitizer::clean($payload), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }

    private function priorityValue(string $priority): int
    {
        return match ($priority) {
            'high' => 90,
            'medium' => 60,
            default => 30,
        };
    }

    private function normalizeSource(string $source): string
    {
        return match ($source) {
            'ai_stub' => 'ai',
            'external_search_stub' => 'external_search',
            'local_storage', 'current_product', 'brand_name_rule', 'category_name_rule' => 'rule',
            default => in_array($source, CatalogEnrichmentTask::SOURCES, true) ? $source : 'rule',
        };
    }
}

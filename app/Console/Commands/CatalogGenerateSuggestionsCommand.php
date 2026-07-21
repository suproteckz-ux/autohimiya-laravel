<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use App\Services\Catalog\AiContentSuggestionService;
use App\Services\Catalog\BrandSuggestionService;
use App\Services\Catalog\CategorySuggestionService;
use App\Services\Catalog\EnrichmentTaskBuilder;
use App\Services\Catalog\ProductImageSuggestionService;
use App\Services\Catalog\ProductProblemDetector;
use App\Support\Utf8Sanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class CatalogGenerateSuggestionsCommand extends Command
{
    protected $signature = 'catalog:generate-suggestions {--type=all : image|description|seo|brand|category|all} {--limit=50} {--dry-run} {--debug}';

    protected $description = 'Generate draft enrichment suggestions without changing products.';

    public function handle(
        ProductProblemDetector $detector,
        EnrichmentTaskBuilder $builder,
        ProductImageSuggestionService $images,
        AiContentSuggestionService $content,
        BrandSuggestionService $brands,
        CategorySuggestionService $categories,
    ): int {
        $type = (string) $this->option('type');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $debug = (bool) $this->option('debug');
        $stats = [
            'processed' => 0,
            'drafts' => 0,
            'failed' => 0,
            'planned' => 0,
            'failed_items' => [],
        ];

        $products = Product::query()
            ->with(['brand', 'category', 'primaryImage'])
            ->withCount('images')
            ->orderBy('id')
            ->get()
            ->filter(fn (Product $product): bool => $detector->detect($product) !== [])
            ->take($limit)
            ->values();

        foreach ($products as $product) {
            $problems = $detector->detect($product);

            if ($this->shouldRun($type, 'image') && in_array(ProductProblemDetector::MISSING_IMAGE, $problems, true)) {
                $payload = $images->suggest($product);
                $this->processSuggestion($product, 'image', $payload, $payload['reason'] ?? 'Image suggestion generated.', $builder, $dryRun, $debug, $stats);
            }

            if ($this->shouldRun($type, 'description') && in_array(ProductProblemDetector::MISSING_DESCRIPTION, $problems, true)) {
                $payload = $content->suggestDescription($product);
                $this->processSuggestion($product, 'description', $payload, $payload['reason'] ?? 'Description draft generated.', $builder, $dryRun, $debug, $stats);
            }

            if ($this->shouldRun($type, 'seo') && in_array(ProductProblemDetector::MISSING_SEO, $problems, true)) {
                $payload = $content->suggestSeo($product);
                $this->processSuggestion($product, 'seo_title', $payload, $payload['reason'] ?? 'SEO title draft generated.', $builder, $dryRun, $debug, $stats);
                $this->processSuggestion($product, 'seo_description', $payload, $payload['reason'] ?? 'SEO description draft generated.', $builder, $dryRun, $debug, $stats);
            }

            if ($this->shouldRun($type, 'brand') && in_array(ProductProblemDetector::MISSING_BRAND, $problems, true)) {
                $payload = $brands->suggest($product);

                if ((int) ($payload['confidence'] ?? 0) >= 70) {
                    $this->processSuggestion($product, 'brand', $payload, $payload['reason'] ?? 'Brand suggestion generated.', $builder, $dryRun, $debug, $stats);
                } else {
                    $this->processDebug($product, null, 'brand', $payload, $debug, 'skipped_low_confidence');
                }
            }

            if ($this->shouldRun($type, 'category') && in_array(ProductProblemDetector::MISSING_CATEGORY, $problems, true)) {
                $payload = $categories->suggest($product);

                if ((int) ($payload['confidence'] ?? 0) >= 70) {
                    $this->processSuggestion($product, 'category', $payload, $payload['reason'] ?? 'Category suggestion generated.', $builder, $dryRun, $debug, $stats);
                } else {
                    $this->processDebug($product, null, 'category', $payload, $debug, 'skipped_low_confidence');
                }
            }
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $dryRun ? 'dry-run' : 'apply'],
            ['Type', $type],
            ['Products checked', $products->count()],
            ['Tasks processed', $stats['processed']],
            [$dryRun ? 'Suggestions planned' : 'Drafts created/updated', $dryRun ? $stats['planned'] : $stats['drafts']],
            ['Failed', $stats['failed']],
            ['Products changed', 0],
        ]);

        if ($stats['failed_items'] !== []) {
            $this->warn('First failed items:');
            $this->table(['SKU', 'Task ID', 'Task type', 'Error'], $stats['failed_items']);
        }

        return self::SUCCESS;
    }

    private function processSuggestion(
        Product $product,
        string $taskType,
        array $payload,
        string $reason,
        EnrichmentTaskBuilder $builder,
        bool $dryRun,
        bool $debug,
        array &$stats,
    ): void {
        $stats['processed']++;
        $payload = Utf8Sanitizer::clean($payload);
        $jsonOk = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) !== false;

        if ($dryRun) {
            $stats['planned']++;
            $this->processDebug($product, null, $taskType, $payload, $debug, $jsonOk ? 'json_ok' : 'json_fail');

            return;
        }

        try {
            $task = $builder->createTask($product, $taskType, $reason, $payload, (int) ($payload['confidence'] ?? 0));
            $stats['drafts']++;
            $this->processDebug($product, $task, $taskType, $payload, $debug, $jsonOk ? 'json_ok' : 'json_fail');
        } catch (Throwable $exception) {
            $stats['failed']++;
            $task = $this->markFailed($product, $taskType, $exception);

            if (count($stats['failed_items']) < 5) {
                $stats['failed_items'][] = [
                    $product->paloma_sku ?: $product->sku ?: $product->model ?: 'n/a',
                    $task?->id ?: 'n/a',
                    $taskType,
                    Utf8Sanitizer::cleanString($exception->getMessage()),
                ];
            }

            $this->processDebug($product, $task, $taskType, $payload, $debug, 'failed: '.$exception->getMessage());
        }
    }

    private function markFailed(Product $product, string $taskType, Throwable $exception): ?CatalogEnrichmentTask
    {
        try {
            return CatalogEnrichmentTask::query()->updateOrCreate([
                'product_id' => $product->id,
                'task_type' => $taskType,
                'status' => 'failed',
                'source' => 'rule',
            ], [
                'priority' => 90,
                'confidence' => 0,
                'reason' => 'Suggestion generation failed.',
                'error_message' => Str::limit($exception->getMessage(), 1000),
                'current_value' => $product->display_name,
                'current_payload' => [
                    'product_id' => $product->id,
                    'sku' => $product->paloma_sku ?: $product->sku ?: $product->model,
                ],
                'payload_json' => [
                    'failed_by' => 'catalog:generate-suggestions',
                    'exception' => get_class($exception),
                ],
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function processDebug(Product $product, ?CatalogEnrichmentTask $task, string $taskType, array $payload, bool $debug, string $status): void
    {
        if (! $debug) {
            return;
        }

        $source = $payload['source'] ?? $task?->source ?? 'n/a';
        $jsonOk = json_encode(Utf8Sanitizer::clean($payload), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) !== false ? 'ok' : 'fail';

        $this->line(sprintf(
            'product_id=%s sku=%s task_id=%s task_type=%s source=%s json=%s status=%s',
            $product->id,
            $product->paloma_sku ?: $product->sku ?: $product->model ?: 'n/a',
            $task?->id ?: 'n/a',
            $taskType,
            $source,
            $jsonOk,
            Utf8Sanitizer::cleanString($status),
        ));
    }

    private function shouldRun(string $requested, string $type): bool
    {
        return $requested === 'all' || $requested === $type;
    }
}

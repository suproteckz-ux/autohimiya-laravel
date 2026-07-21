<?php

namespace App\Console\Commands;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use App\Services\Catalog\EnrichmentTaskBuilder;
use App\Services\Catalog\ProductProblemDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class CatalogCreateEnrichmentTasksCommand extends Command
{
    protected $signature = 'catalog:create-enrichment-tasks {--type=all : image|description|seo|brand|category|all} {--limit=100} {--dry-run}';

    protected $description = 'Create draft enrichment tasks for products with missing content.';

    public function handle(ProductProblemDetector $detector, EnrichmentTaskBuilder $builder): int
    {
        $type = (string) $this->option('type');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $products = $this->problemProducts($detector, $type, $limit);
        $created = 0;
        $updated = 0;

        foreach ($products as $product) {
            if ($dryRun) {
                $created += count($this->typesForProduct($detector, $product, $type));
                continue;
            }

            foreach ($this->typesForProduct($detector, $product, $type) as $taskType) {
                $types = $taskType === 'seo' ? ['seo_title', 'seo_description'] : [$taskType];
                $existing = CatalogEnrichmentTask::query()
                    ->where('product_id', $product->id)
                    ->whereIn('task_type', $types)
                    ->whereIn('status', EnrichmentTaskBuilder::ACTIVE_STATUSES)
                    ->count();

                $result = match ($taskType) {
                    'image' => $builder->createImageTask($product),
                    'description' => $builder->createDescriptionTask($product),
                    'seo' => $builder->createSeoTask($product),
                    'brand' => $builder->createBrandTask($product),
                    'category' => $builder->createCategoryTask($product),
                    default => null,
                };

                $affected = is_array($result) ? count(array_filter($result)) : (int) filled($result);
                $updated += min($existing, $affected);
                $created += max(0, $affected - $existing);
            }
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $dryRun ? 'dry-run' : 'apply'],
            ['Type', $type],
            ['Products checked', $products->count()],
            [$dryRun ? 'Tasks planned' : 'Tasks created', $created],
            [$dryRun ? 'Tasks updated' : 'Tasks updated', $updated],
        ]);

        return self::SUCCESS;
    }

    private function problemProducts(ProductProblemDetector $detector, string $type, int $limit): Collection
    {
        return Product::query()
            ->with(['brand', 'category', 'primaryImage'])
            ->withCount('images')
            ->orderBy('id')
            ->get()
            ->filter(fn (Product $product): bool => $this->typesForProduct($detector, $product, $type) !== [])
            ->take($limit)
            ->values();
    }

    private function typesForProduct(ProductProblemDetector $detector, Product $product, string $type): array
    {
        $problems = $detector->detect($product);
        $types = [];

        if (($type === 'all' || $type === 'image') && in_array(ProductProblemDetector::MISSING_IMAGE, $problems, true)) {
            $types[] = 'image';
        }
        if (($type === 'all' || $type === 'description') && in_array(ProductProblemDetector::MISSING_DESCRIPTION, $problems, true)) {
            $types[] = 'description';
        }
        if (($type === 'all' || $type === 'seo') && in_array(ProductProblemDetector::MISSING_SEO, $problems, true)) {
            $types[] = 'seo';
        }
        if (($type === 'all' || $type === 'brand') && in_array(ProductProblemDetector::MISSING_BRAND, $problems, true)) {
            $types[] = 'brand';
        }
        if (($type === 'all' || $type === 'category') && in_array(ProductProblemDetector::MISSING_CATEGORY, $problems, true)) {
            $types[] = 'category';
        }

        return $types;
    }
}

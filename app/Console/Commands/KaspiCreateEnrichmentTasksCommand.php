<?php

namespace App\Console\Commands;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Support\ContentScore;
use Illuminate\Console\Command;

class KaspiCreateEnrichmentTasksCommand extends Command
{
    protected $signature = 'kaspi:create-enrichment-tasks {--limit=10} {--dry-run}';

    protected $description = 'Create Kaspi enrichment task records for products with missing content.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $planned = 0;

        $products = Product::query()
            ->withCount('images')
            ->with(['primaryImage', 'attributes'])
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($products as $product) {
            $missingPhoto = ! ContentScore::hasPhoto($product);
            $missingDescription = ! ContentScore::hasDescription($product);
            $missingAttributes = $product->attributes->isEmpty();

            if (! $missingPhoto && ! $missingDescription && ! $missingAttributes) {
                continue;
            }

            $planned++;

            if ($dryRun) {
                continue;
            }

            KaspiEnrichmentTask::query()->firstOrCreate([
                'product_id' => $product->id,
                'status' => 'pending',
            ], [
                'kaspi_merchant_sku' => $product->sku,
                'kaspi_product_url' => $product->kaspi_product_url,
                'missing_photo' => $missingPhoto,
                'missing_description' => $missingDescription,
                'missing_attributes' => $missingAttributes,
                'source' => 'manual',
            ]);
            $created++;
        }

        $this->table(['Metric', 'Value'], [
            ['Mode', $dryRun ? 'dry-run' : 'apply'],
            ['Products checked', $products->count()],
            ['Tasks planned', $planned],
            ['Tasks created', $created],
            ['Products changed', 0],
        ]);

        return self::SUCCESS;
    }
}

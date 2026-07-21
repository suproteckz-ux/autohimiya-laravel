<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\StorefrontText;
use App\Support\Utf8Sanitizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class CatalogContentGapsCommand extends Command
{
    protected $signature = 'catalog:content-gaps
        {--csv : Save CSV report}
        {--limit=100 : Max products to scan, 0 = all}
        {--category= : Filter by category id or name}
        {--status= : Filter by product_status or Kaspi task status}';

    protected $description = 'Report products that still need content cleanup or manual review.';

    public function handle(): int
    {
        $query = Product::query()
            ->with(['category', 'images', 'attributes', 'kaspiEnrichmentTasks' => fn ($q) => $q->latest('updated_at')])
            ->withCount(['images', 'attributes'])
            ->orderBy('id');

        if (filled($this->option('category'))) {
            $category = (string) $this->option('category');
            $query->whereHas('category', fn (Builder $q) => is_numeric($category)
                ? $q->whereKey((int) $category)
                : $q->where('name', 'like', "%{$category}%"));
        }

        if (filled($this->option('status'))) {
            $status = (string) $this->option('status');
            $query->where(fn (Builder $q) => $q
                ->where('product_status', $status)
                ->orWhereHas('kaspiEnrichmentTasks', fn (Builder $task) => $task->where('status', $status)));
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = [];
        $counts = [
            'no_photo' => 0,
            'no_description' => 0,
            'no_attributes' => 0,
            'broken_utf8' => 0,
            'new_goods_category' => 0,
            'category_missing' => 0,
            'slug_looks_like_sku' => 0,
            'kaspi_url_missing' => 0,
            'kaspi_no_data' => 0,
        ];

        foreach ($query->get() as $product) {
            $latestTask = $product->kaspiEnrichmentTasks->first();
            $flags = $this->flags($product, $latestTask?->status);

            foreach ($counts as $key => $value) {
                if (in_array($key, $flags, true)) {
                    $counts[$key]++;
                }
            }

            if ($flags !== []) {
                $rows[] = [
                    'product_id' => $product->id,
                    'sku' => $product->sku ?: $product->paloma_sku ?: $product->model,
                    'name' => StorefrontText::plain($product->name),
                    'category' => StorefrontText::plain($product->category?->name),
                    'status' => $product->product_status,
                    'kaspi_status' => $latestTask?->status,
                    'flags' => implode('|', $flags),
                    'kaspi_url' => $product->kaspi_product_url,
                ];
            }
        }

        $this->table(['Metric', 'Count'], collect($counts)->map(fn (int $count, string $metric): array => [$metric, $count])->values()->all());
        $this->table(['product_id', 'sku', 'name', 'category', 'status', 'kaspi_status', 'flags', 'kaspi_url'], array_slice($rows, 0, 50));

        if ((bool) $this->option('csv')) {
            $path = 'reports/content-gaps-'.now()->format('Ymd-His').'.csv';
            Storage::disk('local')->put($path, $this->csv($rows));
            $this->info('CSV saved: storage/app/'.$path);
        }

        return self::SUCCESS;
    }

    private function flags(Product $product, ?string $kaspiStatus): array
    {
        $flags = [];
        $description = StorefrontText::plain($product->description);
        $categoryName = StorefrontText::plain($product->category?->name);
        $sku = StorefrontText::plain($product->sku ?: $product->paloma_sku ?: $product->model);

        if ((int) $product->images_count === 0 && blank($product->primary_image)) {
            $flags[] = 'no_photo';
        }

        if ($description === '' || mb_strtolower($description) === mb_strtolower('Описание готовится')) {
            $flags[] = 'no_description';
        }

        if ((int) $product->attributes_count === 0) {
            $flags[] = 'no_attributes';
        }

        $contentForBrokenCheck = implode(' ', array_filter([
            $product->name,
            $product->short_description,
            $product->description,
            $product->h1,
            $product->meta_title,
            $product->meta_description,
            ...$product->attributes->flatMap(fn ($attribute) => [$attribute->name, $attribute->value])->all(),
        ]));
        if (Utf8Sanitizer::hasBrokenText($contentForBrokenCheck)) {
            $flags[] = 'broken_utf8';
        }

        if (mb_strtolower($categoryName) === mb_strtolower('Новые товары')) {
            $flags[] = 'new_goods_category';
        }

        if (blank($product->category_id)) {
            $flags[] = 'category_missing';
        }

        if ($sku !== '' && preg_match('/^'.preg_quote($sku, '/').'$/iu', str_replace('-', '', (string) $product->slug)) === 1) {
            $flags[] = 'slug_looks_like_sku';
        }

        if (blank($product->kaspi_product_url)) {
            $flags[] = 'kaspi_url_missing';
        }

        if ($kaspiStatus === 'kaspi_no_data') {
            $flags[] = 'kaspi_no_data';
        }

        return $flags;
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, ['product_id', 'sku', 'name', 'category', 'status', 'kaspi_status', 'flags', 'kaspi_url']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);

        return stream_get_contents($handle) ?: '';
    }
}

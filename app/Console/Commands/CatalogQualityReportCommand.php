<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CatalogQualityReportCommand extends Command
{
    protected $signature = 'catalog:quality-report';

    protected $description = 'Write catalog data quality report for storefront readiness.';

    public function handle(): int
    {
        $stats = [
            'Products total' => Product::query()->count(),
            'Without photo' => Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count(),
            'Without brand' => Product::query()->whereNull('brand_id')->count(),
            'Without description' => Product::query()->where(fn (Builder $query) => $query->whereNull('description')->orWhere('description', ''))->count(),
            'Without SEO' => Product::query()->where(fn (Builder $query) => $query
                ->whereNull('meta_title')->orWhere('meta_title', '')
                ->orWhereNull('meta_description')->orWhere('meta_description', '')
            )->count(),
            'Without category' => Product::query()->whereNull('category_id')->whereDoesntHave('categories')->count(),
            'Without slug' => Product::query()->where(fn (Builder $query) => $query->whereNull('slug')->orWhere('slug', ''))->count(),
            'Duplicate slugs' => (int) Product::query()
                ->select('slug', DB::raw('COUNT(*) as rows_count'))
                ->whereNotNull('slug')
                ->where('slug', '<>', '')
                ->groupBy('slug')
                ->having('rows_count', '>', 1)
                ->get()
                ->sum('rows_count'),
        ];

        $this->writeReport($stats);
        $this->table(['Metric', 'Count'], collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values());
        $this->info('Catalog quality report written to: '.dirname(base_path()).DIRECTORY_SEPARATOR.'CATALOG_QUALITY_REPORT.md');

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $stats
     */
    private function writeReport(array $stats): void
    {
        $lines = [
            '# CATALOG_QUALITY_REPORT',
            '',
            'Дата проверки: 2026-06-17',
            '',
            '| Метрика | Количество |',
            '| --- | ---: |',
        ];

        foreach ($stats as $metric => $count) {
            $lines[] = '| '.$metric.' | '.$count.' |';
        }

        $lines[] = '';
        $lines[] = '## Вывод';
        $lines[] = '';
        $lines[] = 'Основные зоны улучшения данных для следующих этапов: фотографии, бренды, описания и SEO-поля. Эти показатели не блокируют MVP-витрину, но влияют на конверсию, качество карточек и органический трафик.';

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'CATALOG_QUALITY_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
    }
}

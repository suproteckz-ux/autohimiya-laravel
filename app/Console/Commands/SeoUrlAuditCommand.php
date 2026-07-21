<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SeoUrlAuditCommand extends Command
{
    protected $signature = 'seo:url-audit {--write-report : Write SEO_URL_AUDIT_REPORT.md}';

    protected $description = 'Audit product and category storefront slugs.';

    public function handle(): int
    {
        $productDuplicateRows = Product::query()
            ->select('slug', DB::raw('COUNT(*) as rows_count'))
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->groupBy('slug')
            ->having('rows_count', '>', 1)
            ->get();

        $categoryDuplicateRows = Category::query()
            ->select('slug', DB::raw('COUNT(*) as rows_count'))
            ->whereNotNull('slug')
            ->where('slug', '<>', '')
            ->groupBy('slug')
            ->having('rows_count', '>', 1)
            ->get();

        $stats = [
            'Products total' => Product::query()->count(),
            'Products with slug' => Product::query()->whereNotNull('slug')->where('slug', '<>', '')->count(),
            'Products without slug' => Product::query()->where(fn ($query) => $query->whereNull('slug')->orWhere('slug', ''))->count(),
            'Unique product slugs' => Product::query()->whereNotNull('slug')->where('slug', '<>', '')->distinct('slug')->count('slug'),
            'Duplicate product slugs' => (int) $productDuplicateRows->sum('rows_count'),
            'Categories total' => Category::query()->count(),
            'Categories with slug' => Category::query()->whereNotNull('slug')->where('slug', '<>', '')->count(),
            'Categories without slug' => Category::query()->where(fn ($query) => $query->whereNull('slug')->orWhere('slug', ''))->count(),
            'Unique category slugs' => Category::query()->whereNotNull('slug')->where('slug', '<>', '')->distinct('slug')->count('slug'),
            'Duplicate category slugs' => (int) $categoryDuplicateRows->sum('rows_count'),
        ];

        $this->table(['Metric', 'Count'], collect($stats)->map(fn (int $count, string $metric): array => [$metric, $count])->values());

        if ($this->option('write-report')) {
            $this->writeReport($stats, $productDuplicateRows->pluck('slug')->all(), $categoryDuplicateRows->pluck('slug')->all());
            $this->info('SEO URL audit report written to: '.dirname(base_path()).DIRECTORY_SEPARATOR.'SEO_URL_AUDIT_REPORT.md');
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, int> $stats
     * @param array<int, string> $productDuplicates
     * @param array<int, string> $categoryDuplicates
     */
    private function writeReport(array $stats, array $productDuplicates, array $categoryDuplicates): void
    {
        $lines = [
            '# SEO_URL_AUDIT_REPORT',
            '',
            'Дата проверки: 2026-06-17',
            '',
            '## Products',
            '',
            '| Метрика | Количество |',
            '| --- | ---: |',
            '| Products total | '.$stats['Products total'].' |',
            '| Products with slug | '.$stats['Products with slug'].' |',
            '| Products without slug | '.$stats['Products without slug'].' |',
            '| Unique product slugs | '.$stats['Unique product slugs'].' |',
            '| Duplicate product slugs | '.$stats['Duplicate product slugs'].' |',
            '',
            '## Categories',
            '',
            '| Метрика | Количество |',
            '| --- | ---: |',
            '| Categories total | '.$stats['Categories total'].' |',
            '| Categories with slug | '.$stats['Categories with slug'].' |',
            '| Categories without slug | '.$stats['Categories without slug'].' |',
            '| Unique category slugs | '.$stats['Unique category slugs'].' |',
            '| Duplicate category slugs | '.$stats['Duplicate category slugs'].' |',
            '',
            '## Duplicate Details',
            '',
            'Duplicate product slugs: '.($productDuplicates === [] ? 'none' : implode(', ', $productDuplicates)),
            '',
            'Duplicate category slugs: '.($categoryDuplicates === [] ? 'none' : implode(', ', $categoryDuplicates)),
            '',
            '## URL Rules',
            '',
            '- Product route uses `/product/{slug}`.',
            '- Category route uses `/category/{slug}`.',
            '- Slug source must be OpenCart `seo_url.keyword` or `url_alias.keyword` when available.',
        ];

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'SEO_URL_AUDIT_REPORT.md', implode(PHP_EOL, $lines).PHP_EOL);
    }
}

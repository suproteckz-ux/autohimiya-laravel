<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\CatalogRecovery\OpenCartCatalogData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CategoryHierarchyRecoveryCommand extends Command
{
    protected $signature = 'catalog:category-hierarchy {--dry-run} {--apply}';

    protected $description = 'Audit and recover OpenCart category parent hierarchy.';

    public function handle(OpenCartCatalogData $openCart): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Run exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $data = $openCart->all();
        $categories = Category::query()->get()->keyBy('opencart_category_id');
        $restored = 0;
        $missingParents = [];

        foreach ($data['categories'] as $openCartId => $row) {
            $category = $categories[(int) $openCartId] ?? null;

            if (! $category) {
                continue;
            }

            $parentOpenCartId = (int) ($row['parent_id'] ?? 0);
            $parentId = null;

            if ($parentOpenCartId > 0) {
                $parent = $categories[$parentOpenCartId] ?? null;

                if (! $parent) {
                    $missingParents[] = [$openCartId, $category->name, $parentOpenCartId];
                    continue;
                }

                $parentId = $parent->id;
            }

            if ((int) $category->parent_id !== (int) $parentId) {
                $restored++;

                if ($this->option('apply')) {
                    $category->update(['parent_id' => $parentId]);
                }
            }
        }

        $stats = $this->stats($data, $restored);
        $this->writeReports($stats, $missingParents);
        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($count, string $metric): array => [$metric, $count])->values());

        return self::SUCCESS;
    }

    private function stats(array $data, int $restored): array
    {
        $depths = collect($data['category_paths'])
            ->groupBy(fn (array $row): int => (int) $row['category_id'])
            ->map(fn ($rows): int => ((int) collect($rows)->max('level')) + 1);

        return [
            'OpenCart categories total' => count($data['categories']),
            'Laravel categories total' => Category::query()->count(),
            'Root categories' => Category::query()->whereNull('parent_id')->count(),
            'Child categories' => Category::query()->whereNotNull('parent_id')->count(),
            'Max OpenCart depth' => $depths->max() ?: 1,
            'Parent IDs restored' => $restored,
            'Categories without parent' => Category::query()->whereNull('parent_id')->count(),
        ];
    }

    private function writeReports(array $stats, array $missingParents): void
    {
        $tree = Category::query()
            ->whereNull('parent_id')
            ->with('children.children')
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(fn (Category $category): string => $this->treeLine($category))
            ->implode(PHP_EOL);

        $lines = [
            '# CATEGORY_HIERARCHY_AUDIT',
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
        $lines[] = '## Missing Parents';
        $lines[] = '';
        $lines[] = $missingParents === [] ? 'none' : implode(PHP_EOL, array_map(fn ($row): string => implode(', ', $row), $missingParents));
        $lines[] = '';
        $lines[] = '## Tree Examples';
        $lines[] = '';
        $lines[] = '```text';
        $lines[] = $tree !== '' ? $tree : 'No tree data';
        $lines[] = '```';

        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'CATEGORY_HIERARCHY_AUDIT.md', implode(PHP_EOL, $lines).PHP_EOL);

        $fixLines = $lines;
        $fixLines[0] = '# CATEGORY_HIERARCHY_FIX_REPORT';
        File::put(dirname(base_path()).DIRECTORY_SEPARATOR.'CATEGORY_HIERARCHY_FIX_REPORT.md', implode(PHP_EOL, $fixLines).PHP_EOL);
    }

    private function treeLine(Category $category, int $depth = 0): string
    {
        $line = str_repeat('  ', $depth).'- '.$category->display_name;

        foreach ($category->children->sortBy('name') as $child) {
            $line .= PHP_EOL.$this->treeLine($child, $depth + 1);
        }

        return $line;
    }
}

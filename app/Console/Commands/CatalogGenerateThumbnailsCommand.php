<?php

namespace App\Console\Commands;

use App\Models\ProductImage;
use App\Services\Catalog\ProductThumbnailGenerator;
use Illuminate\Console\Command;

class CatalogGenerateThumbnailsCommand extends Command
{
    protected $signature = 'catalog:generate-thumbnails {--dry-run} {--apply}';

    protected $description = 'Generate normalized 600x600 product card thumbnails.';

    public function handle(ProductThumbnailGenerator $generator): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run');

        if ($apply === $dryRun) {
            $this->error('Use exactly one mode: --dry-run or --apply.');

            return self::FAILURE;
        }

        $images = ProductImage::query()
            ->with('product:id,name,primary_image')
            ->whereNotNull('path')
            ->orderBy('product_id')
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->get();

        $stats = [
            'images' => $images->count(),
            'created' => 0,
            'would_create' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $rows = [];

        foreach ($images as $image) {
            $result = $generator->make($image, $apply);
            $status = (string) $result['status'];

            if ($status === 'created') {
                $stats['created']++;
            } elseif ($status === 'would_create') {
                $stats['would_create']++;
            } elseif (str_contains($status, 'error') || in_array($status, ['missing_source', 'broken_source', 'unsupported_source'], true)) {
                $stats['errors']++;
            } else {
                $stats['skipped']++;
            }

            $rows[] = [
                'product_id' => $image->product_id,
                'image_id' => $image->id,
                'status' => $status,
                'source' => $result['source_path'] ?? '',
                'thumb' => $result['thumb_path'] ?? '',
                'error' => $result['error'] ?? '',
            ];
        }

        $this->table(['Metric', 'Count'], collect($stats)->map(fn ($value, $key) => [$key, $value])->all());

        $reportPath = base_path('../THUMBNAIL_GENERATION_REPORT.md');
        $this->writeReport($reportPath, $stats, $rows, $apply);
        $this->info('Thumbnail report written to: '.$reportPath);

        return self::SUCCESS;
    }

    private function writeReport(string $path, array $stats, array $rows, bool $apply): void
    {
        $lines = [
            '# Thumbnail Generation Report',
            '',
            'Mode: '.($apply ? 'apply' : 'dry-run'),
            '',
            '## Summary',
            '',
        ];

        foreach ($stats as $key => $value) {
            $lines[] = '- '.$key.': '.$value;
        }

        $lines[] = '';
        $lines[] = '## Storage';
        $lines[] = '';
        $lines[] = '- Thumbnails path: `storage/app/public/products/{product_id}/thumbs/{image_id}_card.webp`';
        $lines[] = '- Public URL pattern: `/storage/products/{product_id}/thumbs/{image_id}_card.webp`';
        $lines[] = '';
        $lines[] = '## Errors / Skipped';
        $lines[] = '';

        $problemRows = collect($rows)
            ->filter(fn (array $row): bool => ! in_array($row['status'], ['created', 'would_create'], true))
            ->take(100);

        if ($problemRows->isEmpty()) {
            $lines[] = 'No errors detected.';
        } else {
            $lines[] = '| product_id | image_id | status | error |';
            $lines[] = '| --- | --- | --- | --- |';
            foreach ($problemRows as $row) {
                $lines[] = '| '.$row['product_id'].' | '.$row['image_id'].' | '.$row['status'].' | '.str_replace('|', '/', $row['error']).' |';
            }
        }

        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
    }
}

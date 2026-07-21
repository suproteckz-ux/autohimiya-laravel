<?php

namespace App\Console\Commands;

use App\Support\CatalogUtf8Scanner;
use App\Support\TextEncoding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CatalogRepairEncodingIssuesCommand extends Command
{
    protected $signature = 'catalog:repair-encoding-issues
        {--dry-run : Show safe repairs without changing data}
        {--apply : Apply safe repairs}
        {--limit=0 : Max issues to process}';

    protected $description = 'Safely repair catalog encoding issues without changing prices, stock, SKU, IDs, URLs, or category assignments.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $apply = (bool) $this->option('apply');

        if ($dryRun === $apply) {
            $this->error('Use exactly one option: --dry-run or --apply.');

            return self::FAILURE;
        }

        $issues = CatalogUtf8Scanner::scan(max(0, (int) $this->option('limit')));
        $logPath = storage_path('logs/catalog-repair-encoding-issues-'.now()->format('Ymd-His').'.log');
        File::ensureDirectoryExists(dirname($logPath));

        $rows = [];
        $updated = 0;
        $manualReview = 0;
        $unchanged = 0;

        foreach ($issues as $issue) {
            [$new, $status, $reason] = $this->repairValue($issue);

            if ($status === 'repairable') {
                $rows[] = $this->row($issue, $dryRun ? 'dry-run' : 'updated', $new, $reason);
                $this->writeLog($logPath, $issue, $issue['value'], $new, $dryRun ? 'DRY-RUN' : 'UPDATE', $reason);

                if ($apply) {
                    DB::table($issue['table'])
                        ->where('id', $issue['id'])
                        ->update([$issue['column'] => $new]);
                    $updated++;
                }

                continue;
            }

            if ($status === 'unchanged') {
                $unchanged++;
            } else {
                $manualReview++;
                Log::warning('Encoding issue left for manual review.', [
                    'table' => $issue['table'],
                    'column' => $issue['column'],
                    'id' => $issue['id'],
                    'issue' => $issue['issue'],
                    'reason' => $reason,
                ]);
            }

            $rows[] = $this->row($issue, $status, $new, $reason);
            $this->writeLog($logPath, $issue, $issue['value'], $new, strtoupper($status), $reason);
        }

        $this->table(['model', 'id', 'field', 'issue', 'old_sample', 'new_sample', 'status', 'reason'], $rows);
        $this->table(['Metric', 'Value'], [
            ['Issues processed', count($issues)],
            ['Safe repairs '.($dryRun ? 'available' : 'applied'), $dryRun ? collect($rows)->where('status', 'dry-run')->count() : $updated],
            ['Manual review', $manualReview],
            ['Unchanged/skipped', $unchanged],
            ['Log file', $logPath],
        ]);

        return self::SUCCESS;
    }

    private function repairValue(array $issue): array
    {
        if ($issue['column'] === 'slug' || str_ends_with($issue['column'], '_id') || in_array($issue['column'], ['sku', 'paloma_sku', 'model'], true)) {
            return [null, 'manual_review', 'URL/SKU/model fields are never changed automatically'];
        }

        if ($issue['table'] === 'products' && $this->isManualProductField($issue)) {
            return [null, 'manual_review', 'manual product field is protected'];
        }

        $old = $issue['value'];
        $clean = CatalogUtf8Scanner::cleanForColumn($old, $issue['meta']);

        if ($clean === null || $clean === $old) {
            return [null, 'unchanged', 'cleaning produced no safe change'];
        }

        $remainingIssue = TextEncoding::issue($clean);
        if ($remainingIssue !== null && $remainingIssue !== 'html_entity_artifacts') {
            return [null, 'manual_review', 'cleaned value still has '.$remainingIssue];
        }

        if (in_array($issue['issue'], ['replacement_character', 'repeated_question_marks'], true)) {
            return [null, 'manual_review', 'corrupted placeholder needs a trusted source value'];
        }

        return [$clean, 'repairable', 'safe encoding cleanup'];
    }

    private function isManualProductField(array $issue): bool
    {
        $product = DB::table('products')->where('id', $issue['id'])->first([
            'name_is_manual',
            'description_is_manual',
            'seo_is_manual',
            'attributes_are_manual',
        ]);

        if (! $product) {
            return false;
        }

        return match ($issue['column']) {
            'name', 'h1' => (bool) $product->name_is_manual,
            'description', 'short_description' => (bool) $product->description_is_manual,
            'meta_title', 'meta_description' => (bool) $product->seo_is_manual,
            default => false,
        };
    }

    private function row(array $issue, string $status, ?string $new, string $reason): array
    {
        return [
            'model' => $this->modelName($issue['table']),
            'id' => $issue['id'],
            'field' => $issue['column'],
            'issue' => $issue['issue'],
            'old_sample' => $issue['raw_preview'] ?? $issue['preview'],
            'new_sample' => TextEncoding::rawPreview($new),
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private function writeLog(string $path, array $issue, ?string $old, ?string $new, string $status, string $reason): void
    {
        File::append($path, sprintf(
            "[%s] %s table=%s column=%s id=%s issue=%s reason=\"%s\" old=\"%s\" new=\"%s\"%s",
            now()->toDateTimeString(),
            $status,
            $issue['table'],
            $issue['column'],
            $issue['id'],
            $issue['issue'],
            $reason,
            TextEncoding::rawPreview($old, 180),
            TextEncoding::rawPreview($new, 180),
            PHP_EOL
        ));
    }

    private function modelName(string $table): string
    {
        return match ($table) {
            'products' => 'Product',
            'categories' => 'Category',
            'brands' => 'Brand',
            'product_attributes' => 'ProductAttribute',
            default => $table,
        };
    }
}

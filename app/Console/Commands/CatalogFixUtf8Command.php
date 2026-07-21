<?php

namespace App\Console\Commands;

use App\Support\CatalogUtf8Scanner;
use App\Support\TextEncoding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CatalogFixUtf8Command extends Command
{
    protected $signature = 'catalog:fix-utf8 {--dry-run : Show changes without updating the database} {--limit=0 : Max issues to process}';

    protected $description = 'Safely fix invalid UTF-8 and mojibake values in catalog tables.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $issues = CatalogUtf8Scanner::scan(max(0, (int) $this->option('limit')));
        $rows = [];
        $updated = 0;
        $skipped = 0;
        $logPath = storage_path('logs/catalog-fix-utf8-'.now()->format('Ymd-His').'.log');

        File::ensureDirectoryExists(dirname($logPath));

        foreach ($issues as $issue) {
            $old = $issue['value'];
            $new = CatalogUtf8Scanner::cleanForColumn($old, $issue['meta']);

            if ($new === null || $new === $old) {
                $skipped++;
                $rows[] = $this->row($issue, 'skipped', $old, $new);
                continue;
            }

            $rows[] = $this->row($issue, $dryRun ? 'dry-run' : 'updated', $old, $new);
            $this->writeLog($logPath, $issue, $old, $new, $dryRun);

            if (! $dryRun) {
                DB::table($issue['table'])
                    ->where('id', $issue['id'])
                    ->update([$issue['column'] => $new]);

                $updated++;
            }
        }

        $this->table(['table', 'column', 'id', 'issue', 'old_preview', 'new_preview', 'status'], $rows);
        $this->info(($dryRun ? 'Dry-run changes: ' : 'Updated fields: ').($dryRun ? count($rows) - $skipped : $updated));
        $this->info('Skipped unchanged fields: '.$skipped);
        $this->info('Log file: '.$logPath);

        return self::SUCCESS;
    }

    private function row(array $issue, string $status, ?string $old, ?string $new): array
    {
        return [
            $issue['table'],
            $issue['column'],
            $issue['id'],
            $issue['issue'],
            TextEncoding::preview($old),
            TextEncoding::preview($new),
            $status,
        ];
    }

    private function writeLog(string $path, array $issue, string $old, string $new, bool $dryRun): void
    {
        $line = sprintf(
            "[%s] %s table=%s column=%s id=%s issue=%s old=\"%s\" new=\"%s\"%s",
            now()->toDateTimeString(),
            $dryRun ? 'DRY-RUN' : 'UPDATE',
            $issue['table'],
            $issue['column'],
            $issue['id'],
            $issue['issue'],
            TextEncoding::preview($old, 180),
            TextEncoding::preview($new, 180),
            PHP_EOL
        );

        File::append($path, $line);
    }
}

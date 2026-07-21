<?php

namespace App\Console\Commands;

use App\Support\CatalogUtf8Scanner;
use Illuminate\Console\Command;

class CatalogScanUtf8Command extends Command
{
    protected $signature = 'catalog:scan-utf8 {--limit=0 : Max issues to display}';

    protected $description = 'Scan catalog tables for invalid UTF-8 and mojibake values.';

    public function handle(): int
    {
        $issues = CatalogUtf8Scanner::scan(max(0, (int) $this->option('limit')));

        $this->table(
            ['table', 'column', 'id', 'preview', 'issue'],
            array_map(fn (array $issue): array => [
                $issue['table'],
                $issue['column'],
                $issue['id'],
                $issue['preview'],
                $issue['issue'],
            ], $issues)
        );

        $this->info('Encoding issues found: '.count($issues));

        return self::SUCCESS;
    }
}

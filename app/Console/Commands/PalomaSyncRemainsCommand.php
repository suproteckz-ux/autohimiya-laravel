<?php

namespace App\Console\Commands;

use App\Services\Automation\NullProgressReporter;
use App\Services\Paloma\PalomaSyncRemainsService;
use Illuminate\Console\Command;
use Throwable;

class PalomaSyncRemainsCommand extends Command
{
    protected $signature = 'paloma:sync-remains
        {--dry-run}
        {--limit=0}
        {--sku=}
        {--force}
        {--timeout=60}';

    protected $description = 'Sync only Paloma price, stock, availability and sync metadata.';

    public function handle(PalomaSyncRemainsService $service): int
    {
        try {
            $result = $service->sync([
                'dry_run' => (bool) $this->option('dry-run'),
                'limit' => max(0, (int) $this->option('limit')),
                'sku' => trim((string) $this->option('sku')),
                'force' => (bool) $this->option('force'),
                'timeout' => (int) $this->option('timeout'),
            ], new NullProgressReporter());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['sku', 'status', 'price', 'quantity', 'availability'], $result['rows'] ?? []);
        $this->table(['Metric', 'Count'], [
            ['processed', $result['processed_items'] ?? 0],
            ['updated', $result['updated_count'] ?? 0],
            ['created', $result['created_count'] ?? 0],
            ['skipped', $result['skipped_count'] ?? 0],
            ['not_matched', 0],
            ['errors', $result['failed_count'] ?? 0],
        ]);

        return (int) ($result['failed_count'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiOrdersSyncService;
use Illuminate\Console\Command;

class KaspiOrdersSyncCommand extends Command
{
    protected $signature = 'kaspi:orders-sync
        {--dry-run : Show what would be synced without writing to DB}
        {--limit= : Maximum number of orders to process}
        {--from= : Start date for order filter (e.g. 2026-09-01)}
        {--to= : End date for order filter (e.g. 2026-09-19)}
        {--order= : Sync a specific order by Kaspi code}';

    protected $description = 'Sync Kaspi Partner API orders into the local reservation engine';

    public function __construct(private readonly KaspiOrdersSyncService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $mode = config('services.kaspi.orders_mode', 'observe');
        $lookback = config('services.kaspi.orders_lookback_days', 14);
        $this->info("Kaspi orders sync — mode: {$mode}, lookback: {$lookback}d");

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN — no changes will be written.');
        }

        $options = [
            'dry_run' => (bool) $this->option('dry-run'),
            'limit'   => $this->option('limit') ? (int) $this->option('limit') : 0,
            'from'    => $this->option('from'),
            'to'      => $this->option('to'),
            'order'   => $this->option('order'),
        ];

        $result = $this->service->sync($options);

        if (! $result['successful']) {
            $this->error($result['message'] ?? 'Sync failed.');
            return self::FAILURE;
        }

        if ($result['skipped'] ?? false) {
            $this->warn($result['message']);
            return self::SUCCESS;
        }

        $this->info('Sync complete:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Mode',               $result['mode'] ?? $mode],
                ['Dry run',            ($result['dry_run'] ?? false) ? 'yes' : 'no'],
                ['Orders received',    $result['processed'] ?? 0],
                ['Pages',              $result['pages'] ?? 0],
                ['Entries',            $result['entries'] ?? 0],
                ['Created',            $result['created'] ?? 0],
                ['Updated',            $result['updated'] ?? 0],
                ['SKU matched',        $result['sku_matched'] ?? 0],
                ['SKU unmatched',      $result['sku_unmatched'] ?? 0],
                ['Handoff candidates', $result['handoff_candidates'] ?? 0],
                ['Errors',             $result['errors'] ?? 0],
            ]
        );

        return self::SUCCESS;
    }
}

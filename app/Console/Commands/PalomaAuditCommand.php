<?php

namespace App\Console\Commands;

use App\Services\Paloma\PalomaClient;
use App\Services\Paloma\PalomaCatalogAggregator;
use Illuminate\Console\Command;

class PalomaAuditCommand extends Command
{
    protected $signature = 'paloma:audit';

    protected $description = 'Audit Paloma catalog XML without writing to the database.';

    public function handle(PalomaClient $client, PalomaCatalogAggregator $aggregator): int
    {
        $rawOffers = $client->offers();
        $offers = $aggregator->aggregate($rawOffers);
        $stats = PalomaImportCommand::buildStats($rawOffers, $offers);

        $this->info('Paloma catalog audit');
        $this->table(['Metric', 'Value'], PalomaImportCommand::statsRows($stats));

        return self::SUCCESS;
    }
}

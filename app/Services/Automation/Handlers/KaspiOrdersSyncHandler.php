<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationRun;
use App\Services\Automation\AutomationHandlerInterface;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Kaspi\KaspiOrdersSyncService;

class KaspiOrdersSyncHandler implements AutomationHandlerInterface
{
    public function __construct(private readonly KaspiOrdersSyncService $service) {}

    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array
    {
        $context = (array) $run->context;
        $context['dry_run'] = $dryRun || (bool) ($context['dry_run'] ?? false);

        $progress->start(1, 'Kaspi orders sync: fetching orders…');
        $result = $this->service->sync($context);
        $progress->advance();

        return $result;
    }
}

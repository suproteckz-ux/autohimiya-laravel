<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationRun;
use App\Services\Automation\AutomationHandlerInterface;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Paloma\PalomaSyncRemainsService;

class PalomaSyncRemainsHandler implements AutomationHandlerInterface
{
    public function __construct(private readonly PalomaSyncRemainsService $service) {}
    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array
    {
        $context = (array) $run->context;
        $context['dry_run'] = $dryRun || (bool) ($context['dry_run'] ?? false);
        return $this->service->sync($context, $progress);
    }
}
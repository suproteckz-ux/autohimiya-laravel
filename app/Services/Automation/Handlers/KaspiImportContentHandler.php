<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationRun;
use App\Services\Automation\AutomationHandlerInterface;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Kaspi\KaspiContentImportService;

class KaspiImportContentHandler implements AutomationHandlerInterface
{
    public function __construct(private readonly KaspiContentImportService $service) {}
    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array
    {
        $context = (array) $run->context;
        $context['dry_run'] = $dryRun || (bool) ($context['dry_run'] ?? false);
        return $this->service->import($context, $progress);
    }
}
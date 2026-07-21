<?php

namespace App\Services\Automation;

use App\Models\AutomationRun;

interface AutomationHandlerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array;
}
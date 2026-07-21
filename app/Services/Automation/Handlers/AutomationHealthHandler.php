<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationRun;
use App\Services\Automation\AutomationHandlerInterface;
use App\Services\Automation\AutomationHealthService;
use App\Services\Automation\AutomationProgressReporterInterface;

class AutomationHealthHandler implements AutomationHandlerInterface
{
    public function __construct(private readonly AutomationHealthService $health) {}
    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array
    {
        $result = $this->health->inspect();
        $failed = collect($result['rows'])->where('ok', false)->count();
        $progress->start(count($result['rows']), 'Проверка автоматизации.');
        $progress->setProgress(count($result['rows']), count($result['rows']), 'Проверка завершена.');
        return ['successful' => $result['ok'], 'warnings' => ! $result['ok'], 'message' => $result['ok'] ? 'Automation health is OK.' : 'Automation health found warnings.', 'total_items' => count($result['rows']), 'processed_items' => count($result['rows']), 'failed_count' => $failed, 'context' => $result['rows']];
    }
}
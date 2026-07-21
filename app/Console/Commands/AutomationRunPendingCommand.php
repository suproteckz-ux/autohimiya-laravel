<?php

namespace App\Console\Commands;

use App\Services\Automation\AutomationRunner;
use Illuminate\Console\Command;

class AutomationRunPendingCommand extends Command
{
    protected $signature = 'automation:run-pending
        {--type= : Run only one automation type}
        {--run-id= : Run one automation_runs id}
        {--limit=1 : Maximum pending runs per invocation}
        {--dry-run : Mark the selected handler as dry-run where supported}';

    protected $description = 'Run pending automation requests without spawning shell processes.';

    public function handle(AutomationRunner $runner): int
    {
        $summary = $runner->runPending(
            type: filled($this->option('type')) ? (string) $this->option('type') : null,
            runId: filled($this->option('run-id')) ? (int) $this->option('run-id') : null,
            limit: max(1, (int) $this->option('limit')),
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->table(['Metric', 'Count'], collect($summary)->map(fn (int $value, string $key): array => [$key, $value])->values()->all());

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
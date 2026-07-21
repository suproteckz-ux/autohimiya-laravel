<?php

namespace App\Console\Commands;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationType;
use App\Services\Automation\AutomationRunService;
use Illuminate\Console\Command;

class AutomationQueueCommand extends Command
{
    protected $signature = 'automation:queue
        {--type= : Automation type to queue}
        {--source=scheduler : scheduler, admin, manual, system or deployment}
        {--force : Allow duplicate active runs}
        {--context=* : Optional context key=value pairs}';

    protected $description = 'Create an automation run request for the scheduler-safe automation processor.';

    public function handle(AutomationRunService $runs): int
    {
        $type = AutomationType::tryFrom((string) $this->option('type'));
        $source = AutomationRunSource::tryFrom((string) $this->option('source')) ?: AutomationRunSource::Scheduler;

        if (! $type) {
            $this->error('Unknown automation type.');

            return self::FAILURE;
        }

        $result = $runs->request($type, $source, null, $this->parsedContext(), (bool) $this->option('force'));
        $run = $result['run'];

        $this->line(($result['created'] ? 'created' : 'duplicate').': automation_run #'.$run->id.' '.$run->type.' '.$run->status);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function parsedContext(): array
    {
        $context = [];

        foreach ((array) $this->option('context') as $item) {
            if (! is_string($item) || ! str_contains($item, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $item, 2);
            $context[$key] = match (strtolower($value)) {
                'true' => true,
                'false' => false,
                default => is_numeric($value) ? (int) $value : $value,
            };
        }

        return $context;
    }
}
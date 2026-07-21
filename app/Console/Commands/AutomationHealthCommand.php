<?php

namespace App\Console\Commands;

use App\Services\Automation\AutomationHealthService;
use Illuminate\Console\Command;

class AutomationHealthCommand extends Command
{
    protected $signature = 'automation:health';

    protected $description = 'Show automation scheduler and catalog health status without spawning processes.';

    public function handle(AutomationHealthService $health): int
    {
        $result = $health->inspect();
        $this->table(['Metric', 'Value', 'OK'], array_map(fn (array $row): array => [$row['label'], $row['value'], $row['ok'] ? 'yes' : 'no'], $result['rows']));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
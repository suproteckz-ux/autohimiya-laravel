<?php

namespace App\Console\Commands;

use App\Services\Automation\AutomationRunService;
use Illuminate\Console\Command;

class AutomationSchedulerHeartbeatCommand extends Command
{
    protected $signature = 'automation:scheduler-heartbeat {--queue : Also mark the short-lived queue worker heartbeat}';

    protected $description = 'Record scheduler and queue heartbeat timestamps for the admin status page.';

    public function handle(AutomationRunService $runs): int
    {
        $runs->markSchedulerHeartbeat();

        if ((bool) $this->option('queue')) {
            $runs->markQueueHeartbeat();
        }

        $this->info('Automation heartbeat recorded.');

        return self::SUCCESS;
    }
}
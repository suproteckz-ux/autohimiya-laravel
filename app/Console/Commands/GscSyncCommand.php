<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GscSyncCommand extends Command
{
    protected $signature = 'gsc:sync {--days=7} {--dry-run} {--allow-stub}';

    protected $description = 'Placeholder GSC sync command for future real integration.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $this->warn('GSC sync is not implemented in this project phase.');
        $this->line('Requested days: '.$days);
        $this->line('Property URL configured: '.(filled(config('services.gsc.property_url')) ? 'yes' : 'no'));
        $this->line('No real Google credentials are required at this phase.');
        $this->line('No external request was made and no GSC data was imported.');

        if ($this->option('dry-run') || $this->option('allow-stub')) {
            return self::SUCCESS;
        }

        $this->error('Use --dry-run or --allow-stub to acknowledge the current stub behavior.');

        return self::FAILURE;
    }
}

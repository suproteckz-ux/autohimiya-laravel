<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GscSeoOpportunitiesCommand extends Command
{
    protected $signature = 'gsc:seo-opportunities';

    protected $description = 'Placeholder GSC SEO opportunities command.';

    public function handle(): int
    {
        $this->info('GSC SEO opportunities foundation is installed.');
        $this->line('Future cards: low CTR, high impressions, ranking opportunities, orphan URLs, 404 opportunities.');
        $this->line('No Google data is connected yet, so no opportunities are generated.');

        return self::SUCCESS;
    }
}

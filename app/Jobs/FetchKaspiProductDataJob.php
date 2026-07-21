<?php

namespace App\Jobs;

class FetchKaspiProductDataJob
{
    public function __construct(public int $kaspiEnrichmentTaskId, public bool $dryRun = true)
    {
    }

    public function handle(): void
    {
        // Deprecated placeholder. Not used in current automation.
    }
}

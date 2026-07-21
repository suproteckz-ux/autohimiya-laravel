<?php

namespace App\Jobs;

class PublishKaspiEnrichmentDraftJob
{
    public function __construct(public int $kaspiEnrichmentTaskId)
    {
    }

    public function handle(): void
    {
        // Deprecated placeholder. Not used in current automation.
    }
}

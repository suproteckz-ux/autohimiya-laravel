<?php

namespace App\Services\Automation;

class NullProgressReporter implements AutomationProgressReporterInterface
{
    public function start(int $total = 0, ?string $message = null): void {}
    public function advance(int $count = 1, ?string $message = null): void {}
    public function setProgress(?int $total = null, ?int $processed = null, ?string $message = null): void {}
    public function incrementCreated(int $count = 1): void {}
    public function incrementUpdated(int $count = 1): void {}
    public function incrementSkipped(int $count = 1): void {}
    public function incrementFailed(int $count = 1): void {}
    public function heartbeat(?string $message = null): void {}
    public function finish(?string $message = null): void {}
}
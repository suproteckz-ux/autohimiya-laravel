<?php

namespace App\Services\Automation;

use App\Models\AutomationRun;

class DatabaseAutomationProgressReporter implements AutomationProgressReporterInterface
{
    public function __construct(private readonly AutomationRun $run)
    {
    }

    public function start(int $total = 0, ?string $message = null): void
    {
        $this->run->forceFill([
            'total_items' => max(0, $total),
            'processed_items' => 0,
            'progress' => $total > 0 ? 0 : $this->run->progress,
            'heartbeat_at' => now(),
            'message' => $message ?: $this->run->message,
        ])->save();
    }

    public function advance(int $count = 1, ?string $message = null): void
    {
        $this->run->refresh();
        $processed = max(0, $this->run->processed_items + $count);
        $total = max(0, $this->run->total_items);

        $this->run->forceFill([
            'processed_items' => $processed,
            'progress' => $total > 0 ? min(100, (int) floor(($processed / $total) * 100)) : $this->run->progress,
            'heartbeat_at' => now(),
            'message' => $message ?: $this->run->message,
        ])->save();
    }

    public function setProgress(?int $total = null, ?int $processed = null, ?string $message = null): void
    {
        $this->run->refresh();
        $totalValue = $total ?? $this->run->total_items;
        $processedValue = $processed ?? $this->run->processed_items;

        $this->run->forceFill([
            'total_items' => max(0, $totalValue),
            'processed_items' => max(0, $processedValue),
            'progress' => $totalValue > 0 ? min(100, (int) floor(($processedValue / $totalValue) * 100)) : $this->run->progress,
            'heartbeat_at' => now(),
            'message' => $message ?: $this->run->message,
        ])->save();
    }

    public function incrementCreated(int $count = 1): void { $this->incrementCounter('created_count', $count); }
    public function incrementUpdated(int $count = 1): void { $this->incrementCounter('updated_count', $count); }
    public function incrementSkipped(int $count = 1): void { $this->incrementCounter('skipped_count', $count); }
    public function incrementFailed(int $count = 1): void { $this->incrementCounter('failed_count', $count); }

    public function heartbeat(?string $message = null): void
    {
        $this->run->forceFill([
            'heartbeat_at' => now(),
            'message' => $message ?: $this->run->message,
        ])->save();
    }

    public function finish(?string $message = null): void
    {
        $this->run->forceFill([
            'progress' => 100,
            'heartbeat_at' => now(),
            'message' => $message ?: $this->run->message,
        ])->save();
    }

    private function incrementCounter(string $column, int $count): void
    {
        $this->run->refresh();
        $this->run->forceFill([
            $column => max(0, ((int) $this->run->{$column}) + $count),
            'heartbeat_at' => now(),
        ])->save();
    }
}
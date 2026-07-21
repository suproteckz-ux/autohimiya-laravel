<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutomationRunner
{
    public function __construct(
        private readonly AutomationHandlerRegistry $handlers,
        private readonly AutomationRunService $runs,
    ) {
    }

    /**
     * @return array{processed: int, completed: int, failed: int, skipped: int}
     */
    public function runPending(?string $type = null, ?int $runId = null, int $limit = 1, bool $dryRun = false): array
    {
        $this->runs->expireStaleRuns();
        $limit = max(1, $limit);
        $summary = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0];
        $globalLock = Cache::lock('automation:run-pending', max(60, $limit * 300));

        if (! $globalLock->get()) {
            $summary['skipped']++;

            return $summary;
        }

        try {
            $query = AutomationRun::query()
                ->where('status', AutomationRunStatus::Pending->value)
                ->oldest('requested_at')
                ->limit($limit);

            if ($type) {
                $query->where('type', $type);
            }

            if ($runId) {
                $query->whereKey($runId);
            }

            $query->pluck('id')->each(function (int $id) use (&$summary, $dryRun): void {
                $run = AutomationRun::query()->find($id);

                if (! $run) {
                    return;
                }

                $summary['processed']++;
                $completed = $this->runOne($run, $dryRun);
                $summary[$completed ? 'completed' : 'failed']++;
            });
        } finally {
            optional($globalLock)->release();
        }

        return $summary;
    }

    public function runOne(AutomationRun $run, bool $dryRun = false): bool
    {
        $lock = Cache::lock($run->lock_key ?: 'automation:'.$run->type, 3600);

        if (! $lock->get()) {
            $run->forceFill([
                'message' => 'Такая задача уже выполняется.',
                'heartbeat_at' => now(),
            ])->save();

            return false;
        }

        try {
            $run = DB::transaction(function () use ($run): AutomationRun {
                $fresh = AutomationRun::query()->lockForUpdate()->findOrFail($run->id);

                if ($fresh->status !== AutomationRunStatus::Pending->value) {
                    return $fresh;
                }

                $fresh->forceFill([
                    'status' => AutomationRunStatus::Running->value,
                    'started_at' => now(),
                    'heartbeat_at' => now(),
                    'message' => 'Выполняется планировщиком.',
                ])->save();

                return $fresh;
            });

            if ($run->status !== AutomationRunStatus::Running->value) {
                return false;
            }

            $progress = new DatabaseAutomationProgressReporter($run);
            $result = $this->handlers->resolve($run)->handle($run, $progress, $dryRun);
            $failed = (int) ($result['failed_count'] ?? $run->failed_count) > 0;
            $warnings = (bool) ($result['warnings'] ?? false);
            $status = $failed || $warnings ? AutomationRunStatus::CompletedWithWarnings : AutomationRunStatus::Completed;

            $run->refresh();
            $run->forceFill([
                'status' => $status->value,
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'progress' => 100,
                'total_items' => (int) ($result['total_items'] ?? $run->total_items),
                'processed_items' => (int) ($result['processed_items'] ?? $run->processed_items),
                'created_count' => (int) ($result['created_count'] ?? $run->created_count),
                'updated_count' => (int) ($result['updated_count'] ?? $run->updated_count),
                'skipped_count' => (int) ($result['skipped_count'] ?? $run->skipped_count),
                'failed_count' => (int) ($result['failed_count'] ?? $run->failed_count),
                'message' => (string) ($result['message'] ?? 'Задача завершена.'),
                'error_message' => $result['error_message'] ?? null,
            ])->save();

            return true;
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => AutomationRunStatus::Failed->value,
                'finished_at' => now(),
                'heartbeat_at' => now(),
                'message' => 'Задача завершилась ошибкой.',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'failed_count' => max(1, (int) $run->failed_count),
            ])->save();

            return false;
        } finally {
            optional($lock)->release();
        }
    }
}
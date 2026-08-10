<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AutomationRunService
{
    /**
     * @param array<string, mixed> $context
     * @return array{created: bool, run: AutomationRun}
     */
    public function request(AutomationType $type, AutomationRunSource $source, ?User $requestedBy = null, array $context = [], bool $force = false): array
    {
        $lock = Cache::lock('automation:request:'.$type->value, 10);

        if (! $lock->get()) {
            $existing = $this->activeRun($type);
            if ($existing) {
                return ['created' => false, 'run' => $existing];
            }

            throw new RuntimeException('Could not acquire automation request lock.');
        }

        try {
            return DB::transaction(function () use ($type, $source, $requestedBy, $context, $force): array {
                if (! $force && $existing = $this->activeRun($type)) {
                    return ['created' => false, 'run' => $existing];
                }

                $mergedContext = array_replace_recursive($type->defaultContext(), $context);
                $run = AutomationRun::query()->create([
                    'type' => $type->value,
                    'source' => $source->value,
                    'requested_by' => $requestedBy?->id,
                    'status' => AutomationRunStatus::Pending->value,
                    'requested_at' => now(),
                    'heartbeat_at' => now(),
                    'progress' => 0,
                    'context' => $mergedContext,
                    'command_name' => $type->commandName(),
                    'handler' => $type->handlerIdentifier(),
                    'lock_key' => 'automation:'.$type->value,
                    'message' => 'Ожидает запуска планировщиком.',
                ]);

                return ['created' => true, 'run' => $run];
            });
        } finally {
            optional($lock)->release();
        }
    }

    public function activeRun(AutomationType $type): ?AutomationRun
    {
        return AutomationRun::query()
            ->where('type', $type->value)
            ->whereIn('status', AutomationRunStatus::activeValues())
            ->oldest('requested_at')
            ->first();
    }

    /**
     * Create at most one pending continuation for a completed batch.
     *
     * @param array<string, mixed> $context
     * @return array{created: bool, run: AutomationRun}
     */
    public function requestContinuation(AutomationRun $current, array $context): array
    {
        $type = $current->automationType();

        if (! $type) {
            throw new RuntimeException('Cannot continue an unsupported automation type.');
        }

        $lock = Cache::lock('automation:continuation:'.$current->id, 30);

        if (! $lock->get()) {
            throw new RuntimeException('Could not acquire automation continuation lock.');
        }

        try {
            return DB::transaction(function () use ($current, $context, $type): array {
                $existing = AutomationRun::query()
                    ->where('type', $type->value)
                    ->where('status', AutomationRunStatus::Pending->value)
                    ->get()
                    ->first(fn (AutomationRun $run): bool => (int) ($run->context['continued_from_run_id'] ?? 0) === $current->id);

                if ($existing) {
                    return ['created' => false, 'run' => $existing];
                }

                $run = AutomationRun::query()->create([
                    'type' => $type->value,
                    'source' => $current->source,
                    'requested_by' => $current->requested_by,
                    'status' => AutomationRunStatus::Pending->value,
                    'requested_at' => now(),
                    'heartbeat_at' => now(),
                    'progress' => 0,
                    'context' => array_replace_recursive($type->defaultContext(), $context, [
                        'continued_from_run_id' => $current->id,
                    ]),
                    'command_name' => $type->commandName(),
                    'handler' => $type->handlerIdentifier(),
                    'lock_key' => 'automation:'.$type->value,
                    'message' => 'Ожидает продолжения пакетной обработки.',
                ]);

                return ['created' => true, 'run' => $run];
            });
        } finally {
            optional($lock)->release();
        }
    }

    public function markSchedulerHeartbeat(): void
    {
        Cache::put('automation.scheduler_heartbeat_at', now()->toIso8601String(), now()->addHours(2));
    }

    public function markQueueHeartbeat(): void
    {
        Cache::put('automation.queue_heartbeat_at', now()->toIso8601String(), now()->addHours(2));
    }

    public function expireStaleRuns(int $minutes = 120): int
    {
        return AutomationRun::query()
            ->where('status', AutomationRunStatus::Running->value)
            ->where(function ($query) use ($minutes): void {
                $query->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', now()->subMinutes($minutes));
            })
            ->update([
                'status' => AutomationRunStatus::Expired->value,
                'finished_at' => now(),
                'error_message' => 'Задача не обновляла heartbeat дольше допустимого времени.',
                'message' => 'Задача помечена как истекшая.',
                'updated_at' => now(),
            ]);
    }
}

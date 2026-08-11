<?php

namespace App\Console\Commands;

use App\Enums\OzonOperationStatus;
use App\Enums\OzonOperationType;
use App\Models\OzonOperation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class OzonOperationsPruneCommand extends Command
{
    protected $signature = 'ozon:operations-prune
        {--dry-run : Report eligible rows without deleting them}
        {--execute : Explicitly authorize batched deletion}
        {--success-days=14 : Retain successful taxonomy operations for this many days}
        {--failed-days=90 : Retain failed taxonomy operations for this many days}';

    protected $description = 'Prune expired Ozon taxonomy operation history with a mandatory dry-run or execute mode';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if ($dryRun === $execute) {
            $this->components->error('Choose exactly one mode: --dry-run or --execute. Nothing was changed.');

            return self::INVALID;
        }

        $policies = [
            'taxonomy success' => [
                OzonOperationStatus::Completed->value,
                max(1, (int) $this->option('success-days')),
            ],
            'taxonomy failed' => [
                OzonOperationStatus::Failed->value,
                max(1, (int) $this->option('failed-days')),
            ],
        ];
        $rows = [];

        foreach ($policies as $label => [$status, $days]) {
            $query = $this->eligible($status, $days);
            $summary = (clone $query)->selectRaw('COUNT(*) AS rows_count, MIN(created_at) AS oldest, MAX(created_at) AS newest')->first();
            $rows[] = [$label, $status, $days, (int) ($summary?->rows_count ?? 0), $summary?->oldest ?? '—', $summary?->newest ?? '—'];
        }

        $this->table(['policy', 'status', 'retention_days', 'eligible_rows', 'oldest', 'newest'], $rows);

        if ($dryRun) {
            $this->components->info('Dry-run completed. No rows were deleted.');

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($policies as [$status, $days]) {
            do {
                $ids = $this->eligible($status, $days)->orderBy('id')->limit(1000)->pluck('id');
                $batch = $ids->isEmpty() ? 0 : OzonOperation::query()->whereKey($ids)->delete();
                $deleted += $batch;
            } while ($batch === 1000);
        }

        $this->components->info("Deleted {$deleted} expired taxonomy operation rows.");

        return self::SUCCESS;
    }

    private function eligible(string $status, int $days): Builder
    {
        return OzonOperation::query()
            ->where('operation_type', OzonOperationType::TaxonomySync->value)
            ->where('status', $status)
            ->where('created_at', '<', now()->subDays($days));
    }
}

<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Models\SyncLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AutomationHealthService
{
    public function inspect(): array
    {
        $rows = [];
        $dbOk = $this->dbOk();
        $schedulerRegistered = $this->schedulerRegistered();
        $staleRuns = $this->staleRuns();
        $this->row($rows, 'DB connection', $dbOk ? 'ok' : 'failed', $dbOk);
        $this->row($rows, 'Scheduler registered', $schedulerRegistered ? 'yes' : 'no', $schedulerRegistered);
        $this->row($rows, 'Scheduler last heartbeat', Cache::get('automation.scheduler_heartbeat_at') ?: 'never', true);
        $this->row($rows, 'Queue last heartbeat', Cache::get('automation.queue_heartbeat_at') ?: 'never', true);
        $this->row($rows, 'Pending automations', AutomationRun::query()->where('status', AutomationRunStatus::Pending->value)->count(), true);
        $this->row($rows, 'Running automations', AutomationRun::query()->where('status', AutomationRunStatus::Running->value)->count(), true);
        $this->row($rows, 'Failed automations', AutomationRun::query()->where('status', AutomationRunStatus::Failed->value)->count(), true);
        $this->row($rows, 'Stale running jobs', $staleRuns, $staleRuns === 0);
        $this->row($rows, 'Failed queue jobs', Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 'table missing', true);
        $this->row($rows, 'Storage link', file_exists(public_path('storage')) ? 'exists' : 'missing', file_exists(public_path('storage')) || app()->environment('testing'));
        $this->row($rows, 'storage writable', is_writable(storage_path()) ? 'yes' : 'no', is_writable(storage_path()));
        $this->row($rows, 'bootstrap/cache writable', is_writable(base_path('bootstrap/cache')) ? 'yes' : 'no', is_writable(base_path('bootstrap/cache')));
        $this->row($rows, 'APP_ENV', config('app.env'), config('app.env') === 'production' || app()->environment('testing'));
        $this->row($rows, 'APP_DEBUG', config('app.debug') ? 'true' : 'false', ! config('app.debug') || app()->environment('testing'));
        $this->row($rows, 'APP_URL Punycode', config('app.url'), config('app.url') === 'https://xn--80aesatk1az7g.kz' || app()->environment('testing'));
        $this->row($rows, 'PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.3.0', '>='));
        $this->row($rows, 'Last Paloma sync', SyncLog::query()->where('source', 'paloma')->latest('started_at')->value('started_at') ?: 'never', true);
        $this->row($rows, 'Last Kaspi resolve', KaspiEnrichmentTask::query()->where('source', 'widget_browser')->latest('updated_at')->value('updated_at') ?: 'never', true);
        $this->row($rows, 'Last Kaspi import', KaspiEnrichmentTask::query()->whereIn('status', ['kaspi_imported', 'kaspi_partial', 'published'])->latest('updated_at')->value('updated_at') ?: 'never', true);
        $this->row($rows, 'Products requiring category', Product::query()->whereNull('category_id')->count(), true);
        $this->row($rows, 'Products requiring manual name', Product::query()->where('name_is_manual', false)->count(), true);
        $this->row($rows, 'Products without photo', Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count(), true);
        $this->row($rows, 'Products without description', Product::query()->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))->count(), true);
        $this->row($rows, 'Products without specifications', Product::query()->whereDoesntHave('attributes')->count(), true);

        return ['ok' => collect($rows)->every(fn (array $row): bool => (bool) $row['ok']), 'rows' => $rows];
    }

    public function schedulerRegistered(): bool
    {
        $commands = file_get_contents(base_path('routes/console.php')) ?: '';
        return str_contains($commands, 'automation:run-pending') && str_contains($commands, 'automation:queue') && str_contains($commands, 'paloma_sync_remains') && str_contains($commands, 'kaspi_resolve_widget_urls') && str_contains($commands, 'kaspi_import_content');
    }

    private function dbOk(): bool { try { DB::select('select 1'); return true; } catch (\Throwable) { return false; } }
    private function staleRuns(): int { return AutomationRun::query()->where('status', AutomationRunStatus::Running->value)->where(function ($query): void { $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', now()->subHours(2)); })->count(); }
    private function row(array &$rows, string $label, mixed $value, bool $ok): void { $rows[] = ['label' => $label, 'value' => $value, 'ok' => $ok]; }
}
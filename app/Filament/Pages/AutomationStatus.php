<?php

namespace App\Filament\Pages;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Automation\AutomationHealthService;
use App\Services\Automation\AutomationRunService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

class AutomationStatus extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clock';
    protected static string | UnitEnum | null $navigationGroup = 'System';
    protected static ?string $navigationLabel = 'Automation Status';
    protected static ?int $navigationSort = 90;
    protected static ?string $slug = 'automation-status';
    protected string $view = 'filament.pages.automation-status';

    public function getTitle(): string
    {
        return 'Automation Status';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('run_paloma_sync')->label('Run Paloma Sync')->requiresConfirmation()->action(fn () => $this->requestAutomation(AutomationType::PalomaSyncRemains)),
            Action::make('run_kaspi_resolve')->label('Run Kaspi Resolve')->requiresConfirmation()->action(fn () => $this->requestAutomation(AutomationType::KaspiResolveWidgetUrls, ['limit' => 5, 'headless' => true, 'delay_ms' => 3000, 'only_missing_url' => true])),
            Action::make('run_kaspi_import')->label('Run Kaspi Import')->requiresConfirmation()->action(fn () => $this->requestAutomation(AutomationType::KaspiImportContent, ['limit' => 25, 'only_missing' => true, 'force' => false, 'delay_ms' => 3000])),
            Action::make('run_health')->label('Run Automation Health')->action(fn () => $this->requestAutomation(AutomationType::AutomationHealth)),
        ];
    }

    public function statusRows(): array
    {
        return Cache::remember('automation_status_rows', now()->addMinute(), fn (): array => $this->buildStatusRows());
    }

    private function buildStatusRows(): array
    {
        $health = app(AutomationHealthService::class)->inspect();
        $lastPaloma = SyncLog::query()->where('source', 'paloma')->latest('started_at')->first();
        $lastKaspiResolve = KaspiEnrichmentTask::query()->where('source', 'widget_browser')->latest('updated_at')->first();
        $lastKaspiImport = KaspiEnrichmentTask::query()->whereIn('status', ['kaspi_imported', 'kaspi_partial', 'published'])->latest('updated_at')->first();
        $lastFailedRun = AutomationRun::query()->whereIn('status', [AutomationRunStatus::Failed->value, AutomationRunStatus::Expired->value])->latest('finished_at')->first();

        return [
            ['label' => 'Scheduler registered', 'value' => collect($health['rows'])->firstWhere('label', 'Scheduler registered')['value'] ?? 'unknown'],
            ['label' => 'Scheduler last heartbeat', 'value' => Cache::get('automation.scheduler_heartbeat_at') ?: 'never'],
            ['label' => 'Queue last heartbeat', 'value' => Cache::get('automation.queue_heartbeat_at') ?: 'never'],
            ['label' => 'Pending automation count', 'value' => AutomationRun::query()->where('status', AutomationRunStatus::Pending->value)->count()],
            ['label' => 'Running automation count', 'value' => AutomationRun::query()->where('status', AutomationRunStatus::Running->value)->count()],
            ['label' => 'Failed automation count', 'value' => AutomationRun::query()->where('status', AutomationRunStatus::Failed->value)->count()],
            ['label' => 'Last Paloma sync', 'value' => $lastPaloma?->started_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['label' => 'Last Kaspi resolve', 'value' => $lastKaspiResolve?->updated_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['label' => 'Last Kaspi import', 'value' => $lastKaspiImport?->updated_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['label' => 'Last health check', 'value' => AutomationRun::query()->where('type', AutomationType::AutomationHealth->value)->latest('finished_at')->value('finished_at') ?: 'never'],
            ['label' => 'Last failure', 'value' => $lastFailedRun ? $lastFailedRun->type.' #'.$lastFailedRun->id.' '.$this->lastFailedMessage($lastFailedRun) : 'none'],
            ['label' => 'Failed queue jobs count', 'value' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0],
            ['label' => 'Stale running jobs', 'value' => AutomationRun::query()->where('status', AutomationRunStatus::Running->value)->where(fn ($query) => $query->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', now()->subHours(2)))->count()],
            ['label' => 'Products requiring category', 'value' => Product::query()->whereNull('category_id')->count()],
            ['label' => 'Products requiring manual name', 'value' => Product::query()->where('name_is_manual', false)->count()],
            ['label' => 'Products without photo', 'value' => Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count()],
            ['label' => 'Products without description', 'value' => Product::query()->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))->count()],
            ['label' => 'Products without specifications', 'value' => Product::query()->whereDoesntHave('attributes')->count()],
        ];
    }

    private function requestAutomation(AutomationType $type, array $context = []): void
    {
        $result = app(AutomationRunService::class)->request($type, AutomationRunSource::Admin, Auth::user(), $context);
        Cache::forget('automation_status_rows');

        Notification::make()
            ->title($result['created'] ? 'Задача поставлена в очередь выполнения' : 'Такая задача уже ожидает выполнения или выполняется')
            ->body($type->russianLabel().' #'.$result['run']->id)
            ->status($result['created'] ? 'success' : 'warning')
            ->send();

        $this->dispatch('$refresh');
    }

    private function lastFailedMessage(?AutomationRun $run): string
    {
        if (! $run) { return 'none'; }
        return mb_substr(preg_replace('/\s+/', ' ', strip_tags((string) ($run->error_message ?: $run->message ?: 'failed'))) ?: 'failed', 0, 500);
    }
}
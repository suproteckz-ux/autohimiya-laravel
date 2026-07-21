<?php

namespace App\Filament\Pages;

use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Automation\ArtisanProcessRunner;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Throwable;
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
            Action::make('run_paloma_sync')
                ->label('Run Paloma Sync')
                ->requiresConfirmation()
                ->action(fn () => $this->runCommand(['paloma:sync-remains'])),
            Action::make('run_kaspi_resolve')
                ->label('Run Kaspi Resolve')
                ->requiresConfirmation()
                ->modalDescription('This can take several minutes. Manual runs are limited to 5 products; use Scheduler or CLI for mass jobs.')
                ->action(fn () => $this->runCommand(['kaspi:resolve-widget-urls', '--limit=5', '--headless', '--delay-ms=3000', '--only-missing-url=true'], 600)),
            Action::make('run_kaspi_import')
                ->label('Run Kaspi Import')
                ->requiresConfirmation()
                ->modalDescription('This can take several minutes. Use Scheduler or CLI for mass jobs.')
                ->action(fn () => $this->runCommand(['kaspi:import-content', '--limit=25', '--only-missing=true', '--force=false', '--delay-ms=3000'], 900)),
            Action::make('run_health')
                ->label('Run Automation Health')
                ->action(fn () => $this->runCommand(['automation:health'])),
        ];
    }

    /**
     * @return array<int, array{label: string, value: mixed}>
     */
    public function statusRows(): array
    {
        return Cache::remember('automation_status_rows', now()->addMinutes(5), fn (): array => $this->buildStatusRows());
    }

    /**
     * @return array<int, array{label: string, value: mixed}>
     */
    private function buildStatusRows(): array
    {
        $lastPaloma = SyncLog::query()->where('source', 'paloma')->latest('started_at')->first();
        $lastKaspiResolve = KaspiEnrichmentTask::query()->where('source', 'widget_browser')->latest('updated_at')->first();
        $lastKaspiImport = KaspiEnrichmentTask::query()->whereIn('status', ['kaspi_imported', 'kaspi_partial', 'published'])->latest('updated_at')->first();
        $lastFailedLog = SyncLog::query()->where('status', 'failed')->latest('started_at')->first();

        return [
            ['label' => 'Scheduler registered', 'value' => $this->schedulerRegistered() ? 'yes' : 'no'],
            ['label' => 'Last Paloma sync', 'value' => $lastPaloma?->started_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['label' => 'Last Kaspi resolve', 'value' => $lastKaspiResolve?->updated_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['label' => 'Last Kaspi import', 'value' => $lastKaspiImport?->updated_at?->format('Y-m-d H:i:s') ?: 'never'],
            ['label' => 'Last failed job message', 'value' => $this->lastFailedMessage($lastFailedLog)],
            ['label' => 'Products needing category', 'value' => Product::query()->whereNull('category_id')->count()],
            ['label' => 'Products needing manual name', 'value' => Product::query()->where('name_is_manual', false)->count()],
            ['label' => 'Products without photo', 'value' => Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count()],
            ['label' => 'Products without description', 'value' => Product::query()->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))->count()],
            ['label' => 'Products without specifications', 'value' => Product::query()->whereDoesntHave('attributes')->count()],
        ];
    }

    private function runCommand(array $arguments, int $timeout = 300): void
    {
        try {
            $result = app(ArtisanProcessRunner::class)->run($arguments, ['APP_URL' => config('app.url') ?: 'http://127.0.0.1:8000'], $timeout);
        } catch (Throwable $exception) {
            $result = $this->exceptionResult($arguments, $timeout, $exception);
        }

        $diagnostics = $this->diagnosticsFor($result);

        SyncLog::query()->create($this->syncLogPayload([
            'source' => 'automation',
            'mode' => 'manual-run',
            'command' => $result['command'],
            'status' => $result['successful'] ? 'success' : 'failed',
            'started_at' => now()->subMilliseconds($result['duration_ms']),
            'finished_at' => now(),
            'duration_ms' => $result['duration_ms'],
            'error_count' => $result['successful'] ? 0 : 1,
            'payload_summary' => ['exit_code' => $result['exit_code']],
            'diagnostics' => $diagnostics,
            'raw_payload' => ['stdout' => $result['stdout'], 'stderr' => $result['stderr']],
            'error_message' => $result['successful'] ? null : mb_substr($diagnostics['exception_message'] ?: $result['stderr'] ?: $result['stdout'], 0, 2000),
        ]));

        Cache::forget('automation_status_rows');

        Notification::make()
            ->title($result['successful'] ? 'Command finished' : 'Command failed')
            ->body($this->notificationBody($result))
            ->status($result['successful'] ? 'success' : 'danger')
            ->send();

        $this->dispatch('$refresh');
    }

    private function schedulerRegistered(): bool
    {
        $commands = file_get_contents(base_path('routes/console.php')) ?: '';

        return str_contains($commands, 'paloma:sync-remains')
            && str_contains($commands, 'kaspi:resolve-widget-urls')
            && str_contains($commands, 'kaspi:import-content')
            && str_contains($commands, 'catalog:quality-report');
    }

    private function syncLogPayload(array $payload): array
    {
        return $payload;
    }

    private function notificationBody(array $result): string
    {
        if ($result['successful'] ?? false) {
            return mb_substr((string) ($result['stdout'] ?: $result['command']), 0, 500);
        }

        $message = strip_tags((string) ($result['stderr'] ?: $result['stdout'] ?: 'Command failed.'));
        $message = preg_replace('/\s+/', ' ', $message) ?: 'Command failed.';

        if (str_contains($message, 'SQLSTATE')) {
            return 'Command could not connect to the configured database. Check diagnostics in Sync Logs.';
        }

        if (str_contains($message, 'cache_locks')) {
            return 'Command was blocked by cache lock storage. Check cache configuration and Sync Logs diagnostics.';
        }

        return mb_substr($message, 0, 500);
    }

    private function lastFailedMessage(?SyncLog $log): string
    {
        if (! $log) {
            return 'none';
        }

        $message = $log->error_message
            ?: data_get($log->diagnostics, 'exception_message')
            ?: data_get($log->diagnostics, 'stderr')
            ?: data_get($log->diagnostics, 'stdout')
            ?: $log->command
            ?: 'failed';

        return mb_substr(preg_replace('/\s+/', ' ', strip_tags((string) $message)) ?: 'failed', 0, 500);
    }

    private function diagnosticsFor(array $result): array
    {
        $failureText = (string) (($result['exception_message'] ?? null) ?: ($result['stderr'] ?? null) ?: ($result['stdout'] ?? null) ?: '');
        $successful = (bool) ($result['successful'] ?? false);

        return [
            'executed_command' => $result['command'] ?? null,
            'working_directory' => $result['cwd'] ?? base_path(),
            'php_binary' => $result['php_binary'] ?? PHP_BINARY,
            'stdout' => $result['stdout'] ?? '',
            'stderr' => $result['stderr'] ?? '',
            'exception_class' => $successful ? null : (($result['exception_class'] ?? null) ?: $this->extractExceptionClass($failureText)),
            'exception_message' => $successful ? null : (($result['exception_message'] ?? null) ?: $this->extractExceptionMessage($failureText)),
            'stack_trace_first_20_lines' => $successful ? [] : (($result['stack_trace_first_20_lines'] ?? null) ?: $this->firstLines($failureText, 20)),
            'exit_code' => $result['exit_code'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'env_keys' => $result['env_keys'] ?? [],
            'db_connection' => $result['db_connection'] ?? null,
            'db_host' => $result['db_host'] ?? null,
            'db_port' => $result['db_port'] ?? null,
            'db_database' => $result['db_database'] ?? null,
            'cache_store' => $result['cache_store'] ?? null,
        ];
    }

    private function exceptionResult(array $arguments, int $timeout, Throwable $exception): array
    {
        $php = PHP_BINARY ?: 'php';
        $command = implode(' ', array_map(
            fn (string $part): string => str_contains($part, ' ') ? '"'.$part.'"' : $part,
            [$php, 'artisan', ...$arguments]
        ));

        return [
            'cwd' => base_path(),
            'php_binary' => $php,
            'command' => $command,
            'exit_code' => null,
            'successful' => false,
            'stdout' => '',
            'stderr' => '',
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
            'stack_trace_first_20_lines' => array_slice(explode(PHP_EOL, $exception->getTraceAsString()), 0, 20),
            'duration_ms' => 0,
            'timeout' => $timeout,
            'env_keys' => [],
        ];
    }

    private function extractExceptionClass(string $text): ?string
    {
        if (preg_match('/([A-Z][A-Za-z0-9_\\\\]+Exception)\b/', $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractExceptionMessage(string $text): ?string
    {
        $lines = $this->firstLines($text, 5);

        return $lines === [] ? null : implode(PHP_EOL, $lines);
    }

    /**
     * @return array<int, string>
     */
    private function firstLines(string $text, int $limit): array
    {
        return array_slice(
            array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []))),
            0,
            $limit
        );
    }
}

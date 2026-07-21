# Pre-Deployment Automation Verification Report

Date: 2026-07-21
Repository: `C:\Users\anton\OneDrive\Documents\autohimiya-laravel`
Production domain: `https://xn--80aesatk1az7g.kz`

No production, Plesk, deployment, push, or production migration action was performed during this verification.

## 1. Filament Administrator Access

Complete current implementation in `app/Models/User.php`:

```php
<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && (bool) $this->is_admin;
    }
}
```

Administrators are detected exactly by two checks:

1. The requested Filament panel id must be `admin`.
2. The authenticated user record must have `users.is_admin = true`.

There is no environment-based bypass, no email-domain rule, no `return true` for all authenticated users, and no hard-coded password.

### `is_admin` Migration And Compatibility

No new admin field was introduced by this refactor. The clean repository baseline already contains `users.is_admin` in `database/migrations/2026_06_16_000000_create_users_table.php`:

```php
Schema::create('users', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->boolean('is_admin')->default(false)->index();
    $table->rememberToken();
    $table->timestamps();
});
```

Model compatibility:

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'is_admin',
];

protected function casts(): array
{
    return [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
    ];
}
```

Existing production compatibility: current administrator access is preserved if the production admin user already has `is_admin = 1`. If production has a legacy users table without this column, that must be corrected through an approved migration/data update before relying on the production-safe Filament gate.

## 2. Automation Execution Flow

### Admin Button To Business Service

```text
Admin Button
  -> Filament action calls requestAutomation() or requestAutomationRun()
  -> AutomationRunService::request(type, source=admin, requestedBy=current user, context)
  -> creates one automation_runs row with status=pending, unless active duplicate exists
  -> Plesk Scheduled Task runs: php artisan schedule:run
  -> Laravel Scheduler runs: automation:run-pending --limit=1
  -> AutomationRunPendingCommand calls AutomationRunner::runPending()
  -> AutomationRunner locks, selects pending rows, marks one running
  -> AutomationHandlerRegistry resolves handler by AutomationType
  -> Handler calls business service directly
  -> Business service performs Paloma/Kaspi/catalog/health work
  -> AutomationRunner records completed/completed_with_warnings/failed/expired
```

Admin entry points verified:

- `AutomationStatus::run_paloma_sync` -> `AutomationType::PalomaSyncRemains`
- `AutomationStatus::run_kaspi_resolve` -> `AutomationType::KaspiResolveWidgetUrls`
- `AutomationStatus::run_kaspi_import` -> `AutomationType::KaspiImportContent`
- `KaspiSyncCenter` header, row, and bulk operational actions use `requestAutomationRun()` for Kaspi resolve/import.

Handler mapping verified in `AutomationHandlerRegistry`:

```php
return [
    AutomationType::PalomaSyncRemains->value => PalomaSyncRemainsHandler::class,
    AutomationType::KaspiResolveWidgetUrls->value => KaspiResolveWidgetUrlsHandler::class,
    AutomationType::KaspiImportContent->value => KaspiImportContentHandler::class,
    AutomationType::AutomationHealth->value => AutomationHealthHandler::class,
    AutomationType::CatalogQualityReport->value => CatalogQualityReportHandler::class,
];
```

Business services verified:

- `PalomaSyncRemainsHandler` calls `PalomaSyncRemainsService::sync()`.
- `KaspiResolveWidgetUrlsHandler` calls `KaspiWidgetUrlBatchResolver::run()`.
- `KaspiImportContentHandler` calls `KaspiContentImportService::import()`.
- `AutomationHealthHandler` calls `AutomationHealthService::inspect()`.
- `CatalogQualityReportHandler` reads catalog metrics directly.

### Recursion Check

No recursion found. `automation:run-pending` does not create `AutomationRun` records and does not call `automation:queue`. It only selects existing pending rows and executes handlers.

The scheduler creates recurring requests with `automation:queue --type=... --source=scheduler`. The processor consumes them with `automation:run-pending --limit=1`. These are separate commands.

### Duplicate Creation Check

Duplicate prevention is centralized in `AutomationRunService::request()`:

```php
if (! $force && $existing = $this->activeRun($type)) {
    return ['created' => false, 'run' => $existing];
}
```

`activeRun()` checks only same-type `pending` or `running` records:

```php
return AutomationRun::query()
    ->where('type', $type->value)
    ->whereIn('status', AutomationRunStatus::activeValues())
    ->oldest('requested_at')
    ->first();
```

Admin buttons do not pass `force=true`. Scheduled recurring queue creation also does not pass `force=true`. Therefore normal admin and scheduler paths do not create duplicate active runs.

## 3. `schedule:list` After Refactor

Command run locally with safe overrides:

```powershell
$env:APP_URL='https://xn--80aesatk1az7g.kz'; $env:CACHE_STORE='array'; $env:SESSION_DRIVER='array'; $env:QUEUE_CONNECTION='sync'; php artisan schedule:list
```

Output:

```text
*    * * * *  php artisan automation:scheduler-heartbeat
*    * * * *  php artisan automation:run-pending --limit=1
*/5  * * * *  php artisan automation:scheduler-heartbeat --queue
*/5  * * * *  php artisan queue:work --stop-when-empty --tries=3 --timeout=120
*/15 * * * *  php artisan automation:queue --type=paloma_sync_remains --source=scheduler
0    1 * * *  php artisan automation:queue --type=kaspi_resolve_widget_urls --source=scheduler
40   1 * * *  php artisan automation:queue --type=kaspi_import_content --source=scheduler
30   2 * * *  php artisan automation:queue --type=catalog_quality_report --source=scheduler
0    * * * *  php artisan automation:queue --type=automation_health --source=scheduler
```

Duplicate scheduled jobs: none found.

Old direct scheduled business commands removed from scheduler:

- No scheduled `paloma:sync-remains` entry.
- No scheduled `kaspi:resolve-widget-urls` entry.
- No scheduled `kaspi:import-content` entry.
- No duplicated Paloma/Kaspi automation request schedules.

## 4. `AutomationRun` Database Schema

Migration added: `database/migrations/2026_07_21_000001_create_automation_runs_table.php`

Complete schema:

```php
Schema::create('automation_runs', function (Blueprint $table): void {
    $table->id();
    $table->string('type')->index();
    $table->string('source')->default('system')->index();
    $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('status')->default(AutomationRunStatus::Pending->value)->index();
    $table->timestamp('requested_at')->nullable()->index();
    $table->timestamp('started_at')->nullable()->index();
    $table->timestamp('finished_at')->nullable()->index();
    $table->timestamp('heartbeat_at')->nullable()->index();
    $table->unsignedTinyInteger('progress')->default(0);
    $table->unsignedInteger('total_items')->default(0);
    $table->unsignedInteger('processed_items')->default(0);
    $table->unsignedInteger('created_count')->default(0);
    $table->unsignedInteger('updated_count')->default(0);
    $table->unsignedInteger('skipped_count')->default(0);
    $table->unsignedInteger('failed_count')->default(0);
    $table->text('message')->nullable();
    $table->text('error_message')->nullable();
    $table->json('context')->nullable();
    $table->string('command_name')->nullable()->index();
    $table->string('handler')->nullable();
    $table->string('lock_key')->index();
    $table->timestamps();

    $table->index(['type', 'status', 'requested_at']);
    $table->index(['status', 'heartbeat_at']);
});
```

## 5. Paloma Duplicate Button Behavior

Confirmed from `AutomationStatus` and `AutomationRunService`:

- Pressing `Run Paloma Sync` calls `requestAutomation(AutomationType::PalomaSyncRemains)`.
- That calls `AutomationRunService::request()` with `force=false`.
- `request()` checks for an existing same-type `pending` or `running` run before creating a new row.
- If one exists, it returns `created=false` and the existing run.
- Filament shows: `Такая задача уже ожидает выполнения или выполняется`.

Conclusion: pressing `Run Paloma Sync` while another Paloma run is pending or running does not create another `AutomationRun` through the normal admin path.

## 6. Kaspi Duplicate Behavior

Confirmed identical behavior for Kaspi actions:

- `Run Kaspi Resolve` uses `AutomationType::KaspiResolveWidgetUrls`.
- `Run Kaspi Import` uses `AutomationType::KaspiImportContent`.
- KaspiSyncCenter header, row, and bulk actions call the same `requestAutomationRun()` helper.
- That helper calls the same `AutomationRunService::request()` duplicate guard.

Conclusion: pressing Kaspi resolve/import actions while the same Kaspi automation type is pending or running does not create another `AutomationRun` through the normal admin path.

## 7. HTTP Forbidden Process Execution Check

Static search run against HTTP, Filament, automation services, and routes:

```text
Symfony\Component\Process
new Process
Process::
proc_open
shell_exec
exec(
system(
passthru(
popen(
```

Result: no matches in:

- `app/Filament`
- `app/Http`
- `app/Services/Automation`
- `routes`

Therefore no verified HTTP or Filament request path can execute Symfony Process, `proc_open`, `exec`, `shell_exec`, `system`, `passthru`, or `popen` from the inspected code.

Project-wide remaining process usage is limited to `app/Services/Kaspi/KaspiWidgetBrowserResolver.php`, which is a CLI-side Playwright resolver service and is no longer called by Filament or HTTP actions.

## 8. Migrations Added And Production Requirement

Added by this automation refactor:

1. `database/migrations/2026_07_21_000001_create_automation_runs_table.php`

Production requires this migration before the new automation UI and scheduler can record or process automation runs.

Run only after explicit owner approval and a database backup:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan migrate --force
```

No production migration was run by Codex.

Related existing migration inspected:

- `database/migrations/2026_06_16_000000_create_users_table.php` already defines `users.is_admin`.

Existing migration modified for local SQLite test compatibility, not a new production migration:

- `database/migrations/2026_06_30_000003_add_filament_admin_performance_indexes.php`

If this migration has already run in production, the local compatibility edit has no production database effect. If it has not run, MySQL behavior remains the same because the added guard only skips MySQL `information_schema` checks on non-MySQL drivers.

## 9. Final Verification Status

- Admin detection is explicit and production-safe: `panel id = admin` and `users.is_admin = true`.
- Automation execution is scheduler-driven and database-backed.
- No admin/HTTP shell process execution found.
- No recursion found between queue creation and pending-run processing.
- No duplicate scheduled jobs found.
- Paloma and Kaspi duplicate active runs are blocked by the same service guard.
- Production must run the new `automation_runs` migration after backup and owner approval.
- Nothing was pushed.
- Production was not touched.
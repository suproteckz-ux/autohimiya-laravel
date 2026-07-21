# Shared Hosting Automation Implementation Report

## 1. Root Cause

Filament admin actions used a Symfony Process based Artisan runner. Shared web PHP disables `proc_open`, so those actions failed in production even though the underlying Artisan commands can run through Plesk Scheduled Tasks with `/opt/alt/php83/usr/bin/php`.

## 2. Files Inspected

`app/Console`, `app/Jobs`, `app/Services`, `app/Models`, `app/Filament`, `routes/console.php`, `bootstrap/app.php`, `config/queue.php`, `config/cache.php`, `config/session.php`, `database/migrations`, and project-wide process/URL search targets.

## 3. Inventory

| Feature | UI action | Previous implementation | Command | Compatibility | Replacement | Risk |
|---|---|---|---|---|---|---|
| Paloma sync | Run Paloma Sync | Filament called Symfony Process runner | `paloma:sync-remains` | Broken in web PHP | Create `automation_runs` row; scheduler runs service | Medium |
| Kaspi resolve | Run Kaspi Resolve / bulk resolve | Filament called Artisan subprocess | `kaspi:resolve-widget-urls` | Broken in web PHP | Create pending run; CLI scheduler handler calls batch resolver | High |
| Kaspi import | Run Kaspi Import / fetch content | Filament showed CLI or did Livewire HTTP work | `kaspi:import-content` | Risky/blocking in web | Create pending run; scheduler handler imports | High |
| Automation health | Run Automation Health | `Artisan::call('schedule:list')` and process-oriented diagnostics | `automation:health` | Unsafe pattern | DB/cache/filesystem health service | Low |
| Recurring tasks | Scheduler direct commands | Direct `Schedule::command()` for business commands | Paloma/Kaspi/report | Split history and manual status | Scheduler queues unified runs, then processes pending | Medium |
| Queue worker | None documented as short-lived | Operator-dependent | `queue:work` | Supervisor unavailable | Scheduled `--stop-when-empty` worker | Low |

## 4. Files Changed

Changed: `routes/console.php`, `app/Models/User.php`, `app/Filament/Pages/AutomationStatus.php`, `app/Filament/Pages/KaspiSyncCenter.php`, Paloma/Kaspi/health console commands, `DEPLOYMENT.md`.

Removed: obsolete `app/Services/Automation/ArtisanProcessRunner.php`.

## 5. Files Created

Automation enums, model, migration, services, handlers, progress reporters, commands, tests, and docs under `docs/`.

## 6. Database Migrations Added

`2026_07_21_000001_create_automation_runs_table.php` creates `automation_runs` with type, source, requester, status, timestamps, heartbeat, progress, counters, messages, context JSON, handler, command name, and lock key.

## 7. Architecture Before

Filament attempted to start Artisan subprocesses from a web request. Some Kaspi operations also performed network/import work directly in Livewire.

## 8. Architecture After

Filament creates pending `AutomationRun` records only. Plesk runs Laravel Scheduler. Scheduler creates recurring automation runs, records heartbeat, processes pending runs via `automation:run-pending --limit=1`, and optionally starts a short-lived database queue worker.

## 9. Commands Refactored

`paloma:sync-remains`, `kaspi:resolve-widget-urls`, `kaspi:import-content`, and `automation:health` now call reusable services/handlers instead of embedding all automation control in console output or process launching.

## 10. Filament Actions Changed

Automation Status buttons and Kaspi operational row/bulk/header buttons create pending automation requests. Duplicate pending/running requests are blocked and reported with a warning notification.

## 11. Production Access Fix

`App\Models\User` implements `FilamentUser`. `canAccessPanel()` allows the admin panel only when panel id is `admin` and `is_admin` is true. There is no environment-based bypass.

## 12. Scheduler Changes

Scheduler now registers heartbeat, pending-run processing, short-lived queue worker, recurring Paloma, Kaspi resolve, Kaspi import, catalog quality, and automation health request creation.

## 13. Queue Strategy

Database queue remains supported through short-lived `queue:work --stop-when-empty --tries=3 --timeout=120`. Core automation does not require a daemon worker.

## 14. Locks And Duplicate Prevention

`AutomationRunService` uses Laravel cache locks when creating requests. `AutomationRunner` uses a global pending-run lock and per-automation lock keys. Stale running jobs expire when heartbeat is too old.

## 15. Tests Added

Tests cover admin request creation, duplicate prevention, runner execution, successful/failed run state, progress counters, admin access, Punycode APP_URL, scheduler registration, queue strategy, Paloma service use, and forbidden web/admin process calls.

## 16. Static Forbidden-Function Search Result

No forbidden process calls remain in Filament, HTTP, or `app/Services/Automation`. Project-wide remaining occurrence: `app/Services/Kaspi/KaspiWidgetBrowserResolver.php` uses Symfony Process for the CLI-only Playwright resolver. It is not called from Filament or HTTP.

## 17. Deployment Steps

Deploy through Plesk Git, preserve `.env`, run Composer, run migrations only after database backup and owner approval, clear/build caches using `/opt/alt/php83/usr/bin/php`.

## 18. Plesk Tasks Required

Primary: `cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan schedule:run` every minute.

Optional: `cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --tries=3 --timeout=120`.

## 19. Manual Verification Checklist

1. Deploy Git changes manually through Plesk.
2. Preserve `.env`.
3. Run Composer.
4. Run migrations only after backup and approval.
5. Run `optimize:clear` using `/opt/alt/php83/usr/bin/php`.
6. Run config/route/view cache commands.
7. Verify storefront.
8. Verify `/admin/login`.
9. Verify admin authorization in production.
10. Click Run Paloma Sync.
11. Confirm it creates a pending run instead of shell execution.
12. Run `schedule:run` from Plesk.
13. Confirm task changes pending to running to completed.
14. Verify sync logs.
15. Verify duplicate button click is blocked.
16. Verify Kaspi buttons use the same model.
17. Verify no `proc_open` error.
18. Verify failed task produces readable status.
19. Verify `public/storage` remains linked.
20. Verify `APP_ENV=production`, `APP_DEBUG=false`, and Punycode `APP_URL`.

## 20. Known Limitations

Kaspi URL resolution still depends on Node/Playwright from a CLI-only service. If the production PHP CLI also disables process creation, that specific resolver should be run outside PHP or handled by manual URL entry. Paloma sync and Kaspi content import do not require web-triggered shell execution.

## 21. Rollback Instructions

Revert the Plesk Git deployment to the previous commit, run `optimize:clear`, rebuild caches, and restore the database from backup if the `automation_runs` migration has been applied and must be removed.
## 22. Validation Results

- PHP syntax checks: passed for changed app, route, migration, and test files.
- `composer validate`: passed.
- `composer install --no-interaction`: passed after package discovery.
- `php artisan about`: passed with local `APP_URL=https://xn--80aesatk1az7g.kz`; local storage link is not linked, as expected in the clean repo.
- `php artisan route:list`: passed, 54 routes.
- `php artisan optimize:clear`: passed with local safe cache/session/queue overrides.
- `php artisan schedule:list`: passed and shows scheduler heartbeat, pending-run processor, short-lived queue worker, and recurring automation request creation.
- `php artisan test`: passed with 10 tests / 522 assertions. PHPUnit reported warnings from Laravel reading the intentionally absent `.env` file in the clean repository; no test assertions failed.
- Frontend build: skipped because `package.json` has no `build` script.
- Static web/admin forbidden process search: no matches in `app/Filament`, `app/Http`, or `app/Services/Automation`.
- Project-wide forbidden process search: remaining CLI-only `Symfony\Component\Process` usage is documented in `app/Services/Kaspi/KaspiWidgetBrowserResolver.php` for Playwright URL resolution.

## 23. Publication State

No production deployment was performed. No Plesk changes were made. No migrations were run against production. Nothing was pushed.
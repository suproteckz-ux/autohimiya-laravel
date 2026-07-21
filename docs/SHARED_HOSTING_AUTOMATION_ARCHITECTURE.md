# Shared Hosting Automation Architecture

## Hosting Constraints

Production runs on Plesk / Hoster.kz shared hosting. The app is deployed into `/www/xn--80aesatk1az7g.kz`, and the document root is `/www/xn--80aesatk1az7g.kz/public`.

Plesk Laravel Toolkit uses `/usr/bin/php`, which is unsuitable here because the CLI lacks a working MySQL PDO driver. All production Artisan commands must use:

```bash
/opt/alt/php83/usr/bin/php
```

Web PHP disables process-control functions such as `proc_open`. Filament and HTTP requests therefore must never launch shell commands or Symfony Process.

## Final Model

Administrators do not execute automations directly. They create `automation_runs` records with type, source, requester, status, context, counters, heartbeat, and lock key.

`automation:run-pending` is executed by Laravel Scheduler through Plesk Scheduled Tasks. It acquires Laravel cache locks, selects pending runs, marks them running, calls internal Laravel handlers/services, records progress, and marks the run completed, completed with warnings, failed, or expired.

## Manual Admin Flow

1. Admin clicks an action in Filament.
2. Laravel checks for an active run of the same type.
3. If none exists, it creates an `AutomationRun` with status `pending`.
4. Filament shows `Задача поставлена в очередь выполнения`.
5. The HTTP request ends without shell execution.
6. Scheduler later executes the run.

Duplicate active requests show `Такая задача уже ожидает выполнения или выполняется`.

## Scheduler Flow

Plesk runs:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan schedule:run
```

Laravel Scheduler records a heartbeat, queues recurring automation runs, and processes pending runs one at a time with `automation:run-pending --limit=1`.

## Queue Flow

The project may use the database queue for existing jobs, but no permanently running worker is required. The supported worker is short-lived:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --tries=3 --timeout=120
```

Core automation does not require a daemon queue worker because pending runs are handled directly by the scheduler-safe processor.

## Statuses

`pending`, `running`, `completed`, `completed_with_warnings`, `failed`, `cancelled`, `expired`.

## Locking And Retries

`AutomationRunService` prevents duplicate active runs per automation type. `AutomationRunner` uses a global pending-run lock and a per-run lock key. Stale running jobs are marked expired when heartbeat is too old.

Failed runs preserve safe error text in `error_message`. Operators can create a new run after reviewing the failure.

## Process Execution Note

No Filament or HTTP path uses Symfony Process, `proc_open`, `exec`, `shell_exec`, `system`, `passthru`, or `popen`.

The Kaspi widget resolver still contains a CLI-only Playwright subprocess in `KaspiWidgetBrowserResolver`. It is not reachable from Filament or HTTP requests. If production CLI disables process spawning too, run Kaspi URL resolution outside PHP or use manual URL entry; Paloma and Kaspi content import do not need web-triggered shell execution.

## Deployment Procedure

Deploy through Plesk Git. Preserve `.env`, `vendor`, `node_modules`, `public/storage`, uploaded media, runtime cache, and logs.

Post-deploy commands:

```bash
cd www/xn--80aesatk1az7g.kz
/opt/alt/php83/usr/bin/php artisan optimize:clear
composer install --no-dev --prefer-dist --optimize-autoloader
```

Run migrations only with explicit owner approval and a database backup:

```bash
/opt/alt/php83/usr/bin/php artisan migrate --force
```

Then rebuild caches:

```bash
/opt/alt/php83/usr/bin/php artisan config:cache
/opt/alt/php83/usr/bin/php artisan route:cache
/opt/alt/php83/usr/bin/php artisan view:cache
```

Do not automatically run migrations from Git deployment. Do not run `storage:link` unless `public/storage` has been verified missing.

## Rollback

Revert the Git deployment to the previous commit in Plesk, run `optimize:clear`, and rebuild caches with the PHP 8.3 binary. If migrations were applied, restore from the backup made immediately before migration.

## Troubleshooting

- `could not find driver`: the wrong PHP binary was used.
- `Invalid URI: Host is malformed`: `.env` contains the Unicode domain instead of Punycode.
- `proc_open is not available`: a web/admin path is trying to spawn a process and must be replaced by an automation run.
- Pending runs do not move: verify the Plesk Scheduled Task for `schedule:run` and check scheduler heartbeat.
- Queue jobs do not move: run the optional short-lived queue worker command.
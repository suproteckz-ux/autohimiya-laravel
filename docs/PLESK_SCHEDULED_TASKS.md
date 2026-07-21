# Plesk Scheduled Tasks

Production project path:

```bash
www/xn--80aesatk1az7g.kz
```

Correct PHP binary:

```bash
/opt/alt/php83/usr/bin/php
```

Never use `/usr/bin/php` for this application. It is known to run Artisan without a working MySQL PDO driver on this hosting.

## Required

Run Laravel Scheduler every minute:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan schedule:run
```

If Plesk cannot run every minute, use the nearest supported interval. Pending admin requests and recurring automation will run less frequently by that interval.

## Optional Queue Worker

Short-lived database queue worker. This is not a daemon and does not require Supervisor:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan queue:work --stop-when-empty --tries=3 --timeout=120
```

The Laravel Scheduler also registers this short-lived worker every five minutes.

## Manual Checks

Manual Paloma test:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan paloma:sync-remains
```

Run one pending automation request:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan automation:run-pending --limit=1
```

Cache clear:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan optimize:clear
```

Cache build:

```bash
cd www/xn--80aesatk1az7g.kz && /opt/alt/php83/usr/bin/php artisan config:cache && /opt/alt/php83/usr/bin/php artisan route:cache && /opt/alt/php83/usr/bin/php artisan view:cache
```

Do not use `bash artisan83`; there is no `artisan83` wrapper in this project. Do not modify the `artisan` shebang.
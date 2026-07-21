# Deployment Guide

## Purpose

This repository is the clean Laravel 12 source candidate for Autohimiya.kz. It is intended for future Plesk Git deployment and must contain application source only.

## Production Paths

- Production application root: `/www/xn--80aesatk1az7g.kz`
- Production web root: `/www/xn--80aesatk1az7g.kz/public`
- Domain: `https://xn--80aesatk1az7g.kz`

## Files That Must Be Preserved Outside Git

Never overwrite or commit:

- `.env`
- `storage/app/public`
- `public/storage`
- `storage/logs`
- `storage/framework/*`
- database dumps and backups
- production uploaded media
- Plesk-generated hosting files outside Laravel source

## Plesk Deployment Procedure

1. Take a full production file backup.
2. Take a production database backup.
3. Confirm `.env` exists in the production application root and is not replaced by Git.
4. Deploy the Git repository to the application root or a separate release directory.
5. Keep the domain document root pointed to `/public`.
6. Run Composer from Plesk using PHP 8.3.
7. Run Laravel Artisan commands through Laravel Toolkit or Plesk Scheduled Tasks.
8. Validate homepage, catalog, category routes, product routes, images, admin login and logs before switching traffic.

## Composer

Production install command:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Use `composer.lock`. Do not upload or commit `vendor/`.

## Artisan

Run after deploying files and preserving production `.env`:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Do not run migrations until a database backup exists and owner approval is given.

## Scheduler

Use Plesk Scheduler every minute if available:

```bash
/path/to/php /www/xn--80aesatk1az7g.kz/artisan schedule:run
```

## Queue

Without SSH/supervisor, use a short Plesk scheduled worker:

```bash
/path/to/php /www/xn--80aesatk1az7g.kz/artisan queue:work --stop-when-empty --tries=3 --timeout=120
```

For long-running workers, use hosting with supervisor/process manager support.

## Storage Symlink

Production currently has canonical files in `storage/app/public` and may have a temporary physical `public/storage` copy. Safe procedure:

1. STOP: confirm file and database backups.
2. Inventory `public/storage` and `storage/app/public`.
3. Confirm `storage/app/public` contains all required media.
4. Rename the temporary `public/storage` to a dated backup, do not delete.
5. Create symlink: `public/storage -> ../storage/app/public`.
6. Validate multiple product/category image URLs.
7. Keep the renamed backup until owner approves cleanup.

## Rollback

Preferred rollback is release-based:

1. Keep previous production release intact.
2. Switch Plesk document root back to the previous release if validation fails.
3. Restore database backup only if migrations changed schema and forward-fix is not possible.

For direct root deployment, rollback requires restoring the full file backup and database backup if schema changed.

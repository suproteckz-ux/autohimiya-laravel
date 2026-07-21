# SEO Sitemap and Robots Fix Report

## Scope

Fixed local SEO endpoint detection/rendering only. No production, Plesk, deployment, remote push, or server migration actions were performed.

## Sitemap

`/sitemap.xml` now uses `App\Services\Seo\SitemapXmlBuilder` instead of rendering XML through a Blade view. The builder:

- uses `APP_URL` through `App\Support\StorefrontCanonicalUrl` for canonical punycode URLs;
- includes `/`, `/catalog`, `/contacts`, active category URLs, and storefront-visible product URLs;
- emits valid XML with escaped values and stripped invalid XML characters;
- omits empty `lastmod` nodes;
- de-duplicates URLs before rendering.

This removes the fragile Blade XML response path and gives the endpoint a deterministic `application/xml; charset=UTF-8` response.

## Robots

`/robots.txt` now points to the same canonical sitemap URL:

`https://xn--80aesatk1az7g.kz/sitemap.xml`

It allows the public site, blocks admin/search/query variants, and keeps sitemap discovery explicit.

## Validation

- `composer validate`: passed
- `composer install --no-interaction`: passed, nothing installed/updated
- `php artisan about`: passed
- `php artisan route:list`: passed, `/sitemap.xml` and `/robots.txt` registered
- `php artisan optimize:clear`: passed
- `php artisan test`: passed, 17 tests / 558 assertions, warnings only from missing local `.env`
- `php artisan schedule:list`: passed
- SEO tests confirm valid XML, canonical punycode URLs, and no duplicate sitemap `<loc>` entries.

## Production Migration Requirement

No migration was added. Production does not require a database migration for this fix.
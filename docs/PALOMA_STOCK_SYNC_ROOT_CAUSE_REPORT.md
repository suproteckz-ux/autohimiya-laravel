# Paloma Stock Sync Root Cause Report

Date: 2026-08-03

## Scope

- Audited and fixed Paloma stock-only sync behavior.
- No deployment, no production access, no production data changes, and no GitHub push were performed.
- No product content, categories, images, SEO, prices, publication status, Kaspi logic, Plesk settings, or migrations were changed.

## Root Cause

`PalomaSyncRemainsService` already wrote stock as a replacement value:

- `quantity => $offer->stock`
- `stock_quantity => $offer->stock`

The multiplication happened before the write. `PalomaCatalogAggregator::aggregateGroup()` grouped Paloma rows by SKU and calculated:

```php
stock: (int) collect($group)->sum('stock')
```

When Paloma returned repeated offer/availability snapshots for the same SKU, the aggregator treated each repeated snapshot as additional inventory. Four repeated rows with `stockCount=1` became `4`; four repeated rows with `stockCount=4` became `16`.

This was a source aggregation bug, not an Eloquent mutator, database trigger, scheduler duplicate, or additive product update.

## Fix

Stock is now calculated from unique Paloma availability snapshots before product writes:

- Single availability entry: `stock = max(0, stockCount)` when available.
- `available=no`: effective stock is `0`.
- Negative stock: clamped to `0`.
- Decimal/non-integer stock: invalid and ignored.
- Multiple stores: aggregate each unique store once.
- Duplicate nodes with the same SKU/store/effective stock are counted once.
- Same store with conflicting stock values: select the maximum for that store and mark the product sync as a stock conflict.
- Missing/truncated/malformed feed: throws before catalog writes, so existing product quantities are not zeroed.

The sync remains replace-only and idempotent. Re-running the same feed produces the same quantity.

## Missing SKU Rule

Paloma is the only product source in this project. After a full Paloma feed has been successfully downloaded, parsed, and validated as non-empty, stock sync now applies the feed to every row in the `products` table:

- If a product SKU exists in the current complete Paloma feed, `quantity` and `stock_quantity` are replaced with the current Paloma stock.
- If a product SKU does not exist anywhere in the current complete Paloma feed, `quantity` and `stock_quantity` are set to `0`.

No supplier/source flags, migrations, or source-identification logic were added.

Safety behavior:

- Empty feed: sync aborts and product stock is not changed.
- Malformed XML: sync aborts and product stock is not changed.
- Failed Paloma request: sync aborts and product stock is not changed.
- Missing SKU zeroing happens in the same transaction as found-SKU stock replacement.

Partial CLI runs using `--sku` or `--limit` do not perform broad missing-SKU zeroing.

## aut_12 / aut_16 Regression

Local regression fixture reproduces the reported shape:

| SKU | Raw Paloma rows | stockCount in each row | Old stored result | New stored result |
| --- | ---: | ---: | ---: | ---: |
| aut_12 | 4 | 1 | 4 | 1 |
| aut_16 | 4 | 4 | 16 | 4 |

The test `PalomaStockSyncTest::test_sync_replaces_quantity_idempotently_for_aut_12_and_aut_16` verifies:

- `aut_12.quantity = 1`
- `aut_12.stock_quantity = 1`
- `aut_16.quantity = 4`
- `aut_16.stock_quantity = 4`
- A second sync with the same feed keeps `1` and `4`.

## Automation Flow

Admin Button
-> `AutomationRunService::request(AutomationType::PalomaSyncRemains, source=admin)`
-> one `AutomationRun` row in `pending`
-> scheduler runs `automation:run-pending --limit=1`
-> `AutomationRunner` locks the run and marks it `running`
-> `PalomaSyncRemainsHandler`
-> `PalomaSyncRemainsService`
-> Paloma parser/aggregator/business write
-> run marked completed/completed_with_warnings

No recursion was introduced. The handler calls the business service directly and does not create another `AutomationRun`.

Duplicate Paloma runs are prevented in two places:

- `AutomationRunService::activeRun()` prevents another pending/running Paloma `AutomationRun`.
- `PalomaSyncRemainsService` uses a shared `paloma:sync-remains` cache lock so CLI/manual/scheduled service execution cannot overlap.

## Scheduler Verification

`php artisan schedule:list` showed one Paloma queue job and one pending processor:

- `automation:run-pending --limit=1` every minute
- `automation:queue --type=paloma_sync_remains --source=scheduler` every 15 minutes between 09:00 and 20:00

No duplicate Paloma scheduled jobs were present.

## Read-only Audit

`paloma:audit` remains read-only and was extended to compare feed stock against local DB quantity:

- matched products
- missing in DB
- exact stock matches
- stock mismatches
- DB greater/lower than feed
- DB equals feed x4
- SKU detail rows with raw offer count, stores, feed stock, DB quantity, duplicate nodes, invalid nodes

Local command attempted:

```bash
php artisan paloma:audit --sku=aut_12 --sku=aut_16
```

Result: not executable against live Paloma data in this clean local repo because `PALOMA_ENDPOINT` is not configured. No production or remote database was accessed.

## Migrations

No migrations were added.

Production migration requirement: none for this fix.

## Files Changed

- `app/Console/Commands/PalomaAuditCommand.php`
- `app/Console/Commands/PalomaImportCommand.php`
- `app/Services/Paloma/PalomaAvailabilityData.php`
- `app/Services/Paloma/PalomaCatalogAggregator.php`
- `app/Services/Paloma/PalomaClient.php`
- `app/Services/Paloma/PalomaOfferData.php`
- `app/Services/Paloma/PalomaSyncRemainsService.php`
- `tests/Feature/PalomaStockSyncTest.php`
- `docs/PALOMA_STOCK_SYNC_ROOT_CAUSE_REPORT.md`

## Verification Commands

- `php -l` on changed PHP files: passed.
- `php artisan test --filter=PalomaStockSyncTest --display-warnings`: passed, 53 assertions. Warnings are local missing `.env` dotenv warnings.
- `php artisan test`: passed, 611 assertions. Warnings are local missing `.env` dotenv warnings.
- `php artisan schedule:list`: passed, no duplicate Paloma jobs.
- `php artisan list | findstr paloma`: passed.
- `git diff --check`: passed.

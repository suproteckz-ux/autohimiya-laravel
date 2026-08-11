# Ozon database growth prevention hotfix report

Date: 2026-08-11
Status: local implementation and tests only; no commit, push, deploy, production query, API call or cleanup.

## Root cause

Production growth was caused by two copies of the same high-volume taxonomy data:

- every taxonomy tree/attribute/dictionary HTTP request created `ozon_operations` with its complete response JSON;
- every node attribute stored normalized fields plus raw metadata and, for dictionary attributes, all dictionary pages merged into `values_payload`.

Full taxonomy automation iterated every active type node and every dictionary page. This produced about 176,000 operation rows and 270,000 attribute rows in a day, consuming approximately 9.9 GB and 5.48 GB respectively.

## Changes

### Explicit taxonomy operation logging policy

`OzonApiClient` now treats `OzonOperationType::TaxonomySync` separately:

- successful responses store only `logging_policy`, `payload_omitted`, result count and pagination marker;
- request logging stores only safe scalar category/type/attribute/language/pagination identifiers;
- failed responses are redacted and bounded to a 12,000-byte body excerpt;
- oversized failures include `truncated = true` and original byte count;
- endpoint, HTTP method/status, request ID, attempt and timestamps remain in normal operation columns;
- product export/status logging behavior is unchanged.

Dictionary-page responses therefore cannot be stored in full even if the low-level endpoint is called independently.

### Taxonomy attributes are normalized and lightweight

`syncAttributes()` now persists only structural fields:

- attribute ID and name;
- type and dictionary ID;
- required/collection flags;
- sync timestamp.

It sets `values_payload` and `raw_payload` to `NULL` and never calls `/attribute/values`. Existing columns remain unchanged; no migration is introduced.

The on-demand annotation resolver remains intact. It fetches only one category/type attribute response when annotation is missing and caches only the matched annotation metadata.

### Safe taxonomy automation default

The standard `ozon_taxonomy_sync` automation now:

- loads category/type nodes only;
- never starts full attribute batches or continuation runs;
- skips the API call when nodes for the account were synced during the last 24 hours;
- supports an explicit `force_nodes_sync` context for a deliberate nodes refresh;
- still supports an explicitly targeted `ozon_taxonomy_node_id` lookup without dictionary values.

Existing `AutomationRunService` active-run duplicate protection remains in place. Filament's existing “Load taxonomy” action sends only the account ID, so its new default is nodes-only.

### Retention command

Added:

```bash
php artisan ozon:operations-prune --dry-run
```

Default policies:

- successful taxonomy operations: 14 days;
- failed taxonomy operations: 90 days;
- product/export operations: untouched.

The command requires exactly one mode. Without `--dry-run` or explicit `--execute` it exits without changes. It is not registered in scheduler. Actual execution uses indexed filters and 1,000-row ID batches. Only dry-run was tested/executed locally.

### Lightweight storage audit

`db:storage-audit` now skips by default:

- `SUM/AVG/MAX(LENGTH(payload))`;
- payload hashes;
- duplicate grouping by request/response bodies.

These scans require `--deep` and emit a warning that deep mode is not recommended on shared production. Tests capture generated SQL and verify that default mode contains no payload length or MD5 scans.

## Expected growth reduction

For normal nodes sync, operation responses shrink from potentially megabytes per request to a few hundred bytes and no attributes are imported. On-demand annotation creates at most one compact taxonomy operation and one small attribute row per actually used category/type.

The hotfix should eliminate almost all of the observed 15 GB/day taxonomy-related growth. It does not reclaim the existing 15.4 GB; that remains a separately approved cleanup/table-replacement phase after backup.

## Files changed

- `app/Services/Ozon/OzonApiClient.php`
- `app/Services/Ozon/OzonTaxonomyService.php`
- `app/Services/Automation/Handlers/OzonTaxonomySyncHandler.php`
- `app/Console/Commands/OzonOperationsPruneCommand.php`
- `app/Console/Commands/DatabaseStorageAuditCommand.php`
- `tests/Feature/Ozon/OzonReadOnlyApiTest.php`
- `tests/Feature/Ozon/OzonDatabaseGrowthPreventionTest.php`
- `tests/Feature/DatabaseStorageAuditCommandTest.php`
- `docs/OZON_DATABASE_PREVENTION_HOTFIX_REPORT.md`

## Tests covered

- successful taxonomy response is compact;
- failed response is redacted and bounded;
- oversized error is marked truncated;
- dictionary response is not persisted in full;
- attribute sync clears/does not save raw or dictionary payloads;
- nodes selection data remains available;
- standard automation loads nodes only;
- fresh nodes prevent repeated API sync;
- active automation duplicate protection remains;
- annotation on-demand and single-product export tests remain green;
- prune dry-run reports candidates and changes no rows;
- storage audit default does not execute deep scans.

## Remaining cleanup

This hotfix does not delete existing rows, null old payloads or reduce `.ibd` files. Production still requires the separately reviewed sequence documented in `OZON_DATABASE_REDUCTION_PLAN.md`: external backup, stop active taxonomy work, logical cleanup or compact replacement tables, smoke checks, and physical removal only with explicit approval.

## Risks

- any future UI requiring complete Ozon dictionary choices will need a bounded on-demand dictionary cache rather than the removed eager cache;
- a nodes refresh can still return a large tree, but only one compact operation response is logged and nodes remain idempotent;
- prune `--execute` is intentionally available but unscheduled; it must not be run before the cleanup phase is approved;
- product operation payload policy was intentionally left unchanged to avoid affecting export diagnostics and duplicate protection;
- the 24-hour freshness rule means taxonomy node changes may not appear immediately unless an explicitly controlled forced nodes refresh is requested.

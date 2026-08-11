# Ozon emergency storage cleanup plan

Date: 2026-08-11

Status: preparation and local semantic rehearsal only. Production cleanup is not implemented or authorized by this phase.

## 1. Production baseline

- `ozon_operations`: 176,257 rows, 9,913.63 MB; 176,246 are completed `taxonomy_sync`; 2 are running taxonomy operations.
- `ozon_taxonomy_attributes`: 270,017 unique rows, 5,479.56 MB; no business-key duplicates.
- `ozon_taxonomy_nodes`: 9,783 rows, 13.30 MB; must remain.
- total database: 15,440 MB; total `DATA_FREE`: about 47 MB.
- Phase 3 is deployed and no new Ozon operations appeared during the observed hour.

This is real logical data. DELETE alone would not reliably return the `.ibd` capacity and `OPTIMIZE TABLE` is unsafe while quota is exceeded.

## 2. Dependency verdicts

### `ozon_taxonomy_attributes`: SAFE TO EMPTY after backup/freeze

The table has one outgoing FK:

- `ozon_taxonomy_node_id` → `ozon_taxonomy_nodes.id`, `ON DELETE CASCADE`.

No table has a foreign key into `ozon_taxonomy_attributes`. `ozon_products`, Product, Category, user settings and mappings do not reference attribute IDs. Existing Ozon products persist category/type IDs, prepared attributes and payload independently.

Filament category/type selection reads `ozon_taxonomy_nodes`. The on-demand Annotation resolver works when the attributes table is empty: it requests attributes for one selected category/type and caches only the annotation metadata. Phase 3 no longer performs bulk dictionary/attribute import.

Verdict: the 270,017 rows are reproducible cache. Emptying attributes does not remove products, export state, nodes or user configuration.

### `ozon_operations`: SAFE TO COMPACT, NOT SAFE TO EMPTY indiscriminately

Outgoing FKs:

- `ozon_account_id` → `ozon_accounts.id`, `ON DELETE RESTRICT`;
- `ozon_product_id` → `ozon_products.id`, nullable, `ON DELETE CASCADE`;
- `automation_run_id` → `automation_runs.id`, nullable, `ON DELETE SET NULL`.

There are no incoming database FKs to operations. Historical completed taxonomy operations are audit payload only; taxonomy runtime state is stored in nodes/attributes and automation progress in `automation_runs`.

Product workflows use product-linked operations for latest-response UI and duplicate protection. Preserve all non-taxonomy operations. Preserve taxonomy failures and active states for diagnostics/stale review.

Exact selection:

- discard only `operation_type = 'taxonomy_sync' AND status = 'completed'`;
- preserve every `product_export`, `status_check`, `warehouse_sync`, `connection_check`, price/stock/commercial/health/prune type regardless of status;
- preserve `taxonomy_sync` where status is `pending`, `running` or `failed`.

With current counts this discards 176,246 rows and preserves approximately 11 rows.

## 3. The two running taxonomy operations

Use only this metadata query; do not select request/response JSON:

```sql
SELECT
    o.id,
    o.created_at,
    o.updated_at,
    o.started_at,
    o.finished_at,
    o.status,
    o.endpoint,
    o.http_method,
    o.http_status,
    o.request_id,
    o.attempt,
    o.automation_run_id,
    ar.status AS automation_status,
    ar.started_at AS automation_started_at,
    ar.heartbeat_at AS automation_heartbeat_at,
    ar.finished_at AS automation_finished_at
FROM ozon_operations AS o
LEFT JOIN automation_runs AS ar ON ar.id = o.automation_run_id
WHERE o.operation_type = 'taxonomy_sync'
  AND o.status = 'running'
ORDER BY o.id;
```

The `(operation_type, status, created_at)` index narrows the two operation rows; the join uses the automation primary key. An operation is semantically stale if its automation run is finished/failed, absent, or remains running with heartbeat older than the runner's stale threshold and no active worker. Do not infer staleness from operation age alone.

Treatment during compaction: preserve both rows unchanged. Reconcile their status separately after inspecting the indexed metadata and automation runner semantics. They are not allowed to block physical cleanup, but this cleanup must not silently rewrite or discard them.

## 4. Physical cleanup for attributes

Preferred future mechanism: `TRUNCATE TABLE ozon_taxonomy_attributes`, only after verified backup and maintenance freeze.

Why it is preferred over replacement:

- no incoming FK references the table;
- it preserves the existing table definition, indexes and outgoing FK;
- resets AUTO_INCREMENT appropriately for a rebuildable cache;
- with `innodb_file_per_table=ON`, MariaDB normally drops/recreates the table tablespace and returns about 5.48 GB without building a second 5.48 GB copy;
- temporary disk demand is minimal compared with DELETE + OPTIMIZE.

Risks: metadata lock, implicit commit, no transactional rollback, blocked request if a session holds the table. Pause Ozon workers/actions, inspect active sessions with host support, take backup and use a short maintenance window.

Alternative empty-table swap is more complex because FK names and table metadata must be handled and offers no material advantage when the entire child cache can be discarded.

## 5. Physical cleanup for operations

Preferred future mechanism: compact replacement plus atomic table rename.

1. Create `ozon_operations_compact_new` from the live schema with identical columns and indexes.
2. Recreate the three outgoing FK semantics using temporary unique constraint names; `CREATE TABLE ... LIKE` does not reliably copy FKs.
3. Copy rows satisfying the preservation predicate with original IDs and timestamps.
4. Verify preserved counts/groups, column definitions, indexes, FK targets/delete rules and next AUTO_INCREMENT.
5. Atomically rename:
   - `ozon_operations` → `ozon_operations_bulk_backup`;
   - `ozon_operations_compact_new` → `ozon_operations`.
6. Smoke-test models, product operation relations, Ozon UI and export guards.
7. Keep the old table for a separate verification checkpoint. Roll back by reversing the rename if needed.
8. Only after explicit final approval, drop `ozon_operations_bulk_backup` to release up to 9.9 GB.

The compact table contains only a handful of rows, so extra disk usage is small. Constraint names may differ, but referenced columns and delete behavior must be identical. After copied explicit IDs, set/verify AUTO_INCREMENT to at least `MAX(id)+1`.

Do not use DELETE + OPTIMIZE as the primary method. Rebuilding a 9.9 GB table can require comparable temporary capacity, redo and possibly binlog space.

## 6. Phase 5 implementation status

`php artisan ozon:emergency-storage-cleanup` now implements the reviewed two-stage execution and rollback state machine. Destructive modes require `--execute`, the exact confirmation token and `--maintenance-confirmed`; stale running metadata requires an additional explicit override.

No production execution is authorized by implementation alone. Exact guards, commands, rehearsal limits and operating procedure are documented in `OZON_EMERGENCY_STORAGE_CLEANUP_EXECUTION.md`.

## 7. Backup under exceeded quota

Do not write another 15 GB dump into the hosting account. Prefer an external Plesk/Hoster backup destination or stream the dump directly to external storage.

Mandatory business backup:

- `products`, `categories`, `product_images`, `product_attributes`, `category_product`, `brands`, `site_settings`;
- users/orders and all other business-critical catalog/store tables;
- `ozon_products`, `ozon_accounts`, `ozon_warehouses`, `ozon_taxonomy_nodes`;
- the small preserved-operation selection, exported without the 176,246 completed taxonomy rows;
- `automation_runs` related to Ozon;
- `migrations` and schema definitions.

The 270,017 attributes and completed taxonomy response bodies need not be backed up as full payloads because they are reproducible cache/diagnostic history. Preserve counts, date ranges and aggregate metadata in the incident report instead.

## 8. Local rehearsal coverage

Representative fixtures verify:

- default invocation changes nothing;
- wrong confirmation changes nothing;
- even correct `--execute` remains locked and changes nothing;
- only completed taxonomy operations are classified for discard;
- running/failed taxonomy and all non-taxonomy operations are preserved;
- clearing the attribute cache leaves taxonomy nodes intact;
- existing Phase 3 tests confirm category/type nodes and on-demand annotation work with an empty attribute cache.

MariaDB physical DDL must still be rehearsed on an isolated restored database before unlocking execution. SQLite semantic tests cannot prove MariaDB metadata-lock, FK-constraint-name or `.ibd` behavior.

## 9. Downtime and locking

- Attributes TRUNCATE: expected short metadata lock, but can wait indefinitely behind open transactions; use maintenance mode and inspect blockers.
- Operations compact copy: small preserved selection but reading the indexed predicate across 176k rows; expected moderate read time without reading payload for discarded rows, although copied retained rows include their payloads.
- Atomic rename: short metadata lock if no open table users.
- Final old-table drop: metadata lock; filesystem return may take time.

Plan a maintenance window and pause scheduler/queue workers that can access Ozon tables. Paloma/Kaspi jobs should be paused only if the global runner must be stopped; their data is not modified.

## 10. Rollback

Before attributes TRUNCATE: external backup is the only rollback because the cache table is emptied physically. Operational recovery is also possible through nodes plus on-demand annotation, but a full resync must remain disabled.

Before dropping the old operations table: reverse the atomic rename to restore the original table. After drop, rollback requires the external backup of preserved rows/schema; bulk completed taxonomy history is intentionally not required for business recovery.

## 11. Post-cleanup verification

Use the lightweight default audit only:

```bash
php artisan db:storage-audit
```

Expected:

- attributes near empty/minimal;
- operations tiny;
- nodes remain about 9,783;
- Ozon products remain 12;
- total logical DB falls to tens or low hundreds of MB.

Then verify storefront/admin, Ozon export page/list, node-based selector, one controlled on-demand annotation lookup, scheduler health, Paloma and Kaspi. Refresh Plesk statistics and confirm filesystem usage falls.

## 12. Prevention remains active

- normal taxonomy action is nodes-only;
- no bulk attribute or dictionary import;
- successful taxonomy bodies use compact logging;
- attributes resolve on demand;
- emergency cleanup is not scheduled;
- full taxonomy workflow must not be run.

## 13. Future production commands

Current safe inventory command:

```bash
php artisan ozon:emergency-storage-cleanup
```

The requested execution-shaped invocation is:

```bash
php artisan ozon:emergency-storage-cleanup --execute --confirm=REMOVE_OZON_TAXONOMY_CACHE
```

Phase 5 implements this invocation, but it has not been run against production. Production execution remains a separate operator decision requiring verified backup, maintenance preparation and the procedure in `OZON_EMERGENCY_STORAGE_CLEANUP_EXECUTION.md`.

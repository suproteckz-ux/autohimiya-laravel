# Ozon emergency storage cleanup execution

Status: implementation prepared and rehearsed locally. No production execution has occurred.

## Problem

- `ozon_operations`: 176,257 rows / 9,913.63 MB; 176,246 are completed taxonomy history.
- `ozon_taxonomy_attributes`: 270,017 rows / 5,479.56 MB; reproducible cache.
- `ozon_taxonomy_nodes`: 9,783 rows / 13.30 MB; must remain.

Phase 3 stopped regrowth. This command addresses the existing physical allocation without `OPTIMIZE TABLE` and without building another multi-gigabyte copy.

## Preservation rule

The only discarded operation rows are:

```sql
operation_type = 'taxonomy_sync' AND status = 'completed'
```

The exact copy predicate is:

```sql
WHERE NOT (
    operation_type = 'taxonomy_sync'
    AND status = 'completed'
)
```

Everything else is retained, including unknown/future non-taxonomy operation types, original IDs, failures, pending/running taxonomy rows and product/warehouse/connection/status operations.

## Required preparation

Before any execution:

1. create and verify an external backup outside the exceeded hosting quota;
2. stop Ozon taxonomy actions, pending continuations, scheduler worker access and relevant queue traffic;
3. enter the approved maintenance window manually;
4. confirm `ozon_taxonomy_nodes` is populated;
5. inspect the two running taxonomy rows and their AutomationRun heartbeat metadata;
6. do not run full taxonomy sync;
7. ensure no table named `ozon_operations_compact`, `ozon_operations_old` or `ozon_operations_compact_rollback` exists unexpectedly.

The command does not enable maintenance mode, stop scheduler/Plesk tasks or modify AutomationRun status.

## Guards

Every destructive mode requires all of:

```text
--execute
--confirm=REMOVE_OZON_TAXONOMY_CACHE
--maintenance-confirmed
```

If stale running taxonomy records remain, also provide:

```text
--allow-stale-taxonomy-running
```

Fresh-heartbeat or pending taxonomy work always aborts. The stale override preserves stale rows unchanged; it does not mark them failed/completed.

## Dry run

```bash
php artisan ozon:emergency-storage-cleanup
```

It reports logical sizes, row counts, preservation breakdown, active metadata, state and strategy. It performs no `CREATE`, `INSERT`, `TRUNCATE`, `DROP`, `RENAME`, `ALTER`, `DELETE` or `UPDATE`.

## Stage 1

```bash
php artisan ozon:emergency-storage-cleanup \
  --execute \
  --confirm=REMOVE_OZON_TAXONOMY_CACHE \
  --maintenance-confirmed
```

Add `--allow-stale-taxonomy-running` only after the two rows are proven stale and preservation is accepted.

Stage 1:

1. records node/product counts and the attributes schema fingerprint;
2. truncates only `ozon_taxonomy_attributes`;
3. verifies attributes are empty, nodes/products unchanged and schema/indexes remain;
4. prints `ATTRIBUTES CLEANUP VERIFIED`;
5. creates `ozon_operations_compact` with identical columns/indexes/engine/collation;
6. adds missing equivalent outgoing FKs when `CREATE TABLE LIKE` does not copy them;
7. copies every row except completed taxonomy sync, preserving every column and ID;
8. verifies count, operation/status breakdown, schema/index/FK fingerprint and AUTO_INCREMENT;
9. atomically renames live → `ozon_operations_old` and compact → live;
10. verifies the active model table, counters, fingerprints and absence of completed taxonomy rows.

`ozon_operations_old` is deliberately retained. Attribute `.ibd` space should be returned, but the old operations `.ibd` remains until Stage 2.

## Audit and smoke verification between stages

```bash
php artisan db:storage-audit
```

Verify:

- `ozon_taxonomy_attributes` empty/minimal;
- active `ozon_operations` contains the expected handful of rows;
- `ozon_operations_old` still contains original history;
- nodes remain about 9,783;
- Ozon products remain 12;
- admin/storefront/Ozon product list/export page open;
- category/type selector reads nodes;
- one controlled on-demand Annotation path works;
- product operation history and duplicate export guards still work;
- Paloma/Kaspi remain unaffected.

## Stage 2

```bash
php artisan ozon:emergency-storage-cleanup \
  --execute \
  --confirm=REMOVE_OZON_TAXONOMY_CACHE \
  --maintenance-confirmed \
  --drop-old
```

Again add the stale override if preserved running records remain.

Stage 2 first validates active-vs-old fingerprints, preservation breakdown, counts, AUTO_INCREMENT and zero completed taxonomy rows in active. Only then does it drop `ozon_operations_old`. A repeated Stage 2 safely reports that it is already complete.

After the drop, up to approximately 9.9 GB can be returned physically. Refresh Plesk statistics after the final audit.

## Rollback before Stage 2

```bash
php artisan ozon:emergency-storage-cleanup \
  --execute \
  --confirm=REMOVE_OZON_TAXONOMY_CACHE \
  --maintenance-confirmed \
  --rollback
```

Rollback atomically renames current compact live table to `ozon_operations_compact_rollback` and restores `ozon_operations_old` as `ozon_operations`. It drops nothing and keeps the compact copy for analysis.

Rollback does not restore taxonomy attributes. They are reproducible cache and on-demand Annotation remains available. After Stage 2 drops old, rollback is unavailable and aborts safely.

## Resume and failure states

- Original state: live exists, old/compact absent → normal Stage 1.
- Interrupted before rename: live + compact, no old → validate existing compact and resume; no second compact table is created.
- Stage 1 complete: live + old, no compact → repeated Stage 1 is a no-op.
- Unexpected old + compact + live, missing live, or rollback-copy collision → abort without deletion.
- Copy count/fingerprint failure → no rename.
- Attribute verification failure → operations stage never starts.
- Post-rename verification failure → old remains; use guarded rollback after inspection.

## Schema fingerprint

The normalized comparison includes:

- column names, database types, nullability, defaults, auto-increment and column collation;
- primary, unique and normal index semantics/columns;
- FK local/referenced columns, referenced tables and update/delete rules;
- engine and table collation.

Constraint/index names are intentionally ignored because temporary MariaDB FK names must be unique while old and compact coexist. Semantics must match exactly.

## Physical-space reporting

After Stage 1 and Stage 2 the command reports `data_mb`, `index_mb`, `total_mb` and `data_free_mb` for live, old (when present), attributes and nodes, plus total logical DB MB. It never scans payload bodies.

## Irreversibility warning

No backup means original completed taxonomy response history becomes unrecoverable after `--drop-old`. The data is technical history rather than business state, but Stage 2 must not be run until smoke verification and backup approval are complete.

## Final audit

```bash
php artisan db:storage-audit
```

Expected final logical database size: tens to low hundreds of MB, with nodes/products/settings preserved and Phase 3 prevention still active.

## MARIADB REHEARSAL RESULT

The destructive lifecycle was rehearsed on 2026-08-11 in the isolated local database
`autohimiya_cleanup_rehearsal`. The available compatible server was MySQL Community
Server 8.4.3 with InnoDB and `innodb_file_per_table=ON`; neither the working local
`autohimiya` database nor production was opened by the rehearsal.

The opt-in integration profile is:

```powershell
$env:OZON_REHEARSAL_DB='autohimiya_cleanup_rehearsal'
$env:OZON_REHEARSAL_HOST='127.0.0.1'
$env:OZON_REHEARSAL_PORT='3306'
$env:OZON_REHEARSAL_USERNAME='root'
php artisan test tests/Feature/Ozon/OzonEmergencyStorageCleanupMariaDbTest.php
```

The test refuses every database name other than `autohimiya_cleanup_rehearsal` and is
skipped by the normal SQLite suite unless `OZON_REHEARSAL_DB` is explicitly set.

### Compatibility facts

1. `CREATE TABLE ... LIKE` preserved columns, indexes, engine, collation and
   AUTO_INCREMENT properties, but copied **zero foreign keys** on MySQL 8.4.3.
2. The existing Phase 5 fallback detected the missing FK set and added three explicit
   compact-table constraints. The resulting live table had the same FK semantics:
   account `ON DELETE RESTRICT`, product `ON DELETE CASCADE`, and AutomationRun
   `ON DELETE SET NULL`. No command change was required.
3. Index names created for implicit FK support differed while old and compact tables
   coexisted, as expected. Normalized index columns, uniqueness, primary status and
   index type were identical.

### Lifecycle result

- Dry-run: row counts, table list and normalized schemas were byte-for-byte identical
  before and after; no compact/old/rollback table was created.
- Pending guard: the representative pending taxonomy operation aborted Stage 1 before
  any destructive action. After simulating its maintenance-window resolution, the
  stale running taxonomy row was preserved using the explicit stale override.
- Stage 1: attributes were truncated; the first subsequent insert received ID 1;
  attributes indexes/schema remained; nodes and products were unchanged; completed
  taxonomy rows were absent from active; failed/running taxonomy and all non-taxonomy,
  including an unknown future type, retained their original IDs. Active and old
  schema/index/FK/engine/collation fingerprints matched. Old remained available.
- Stage 1 re-run: detected the already-staged live + old state and made no changes.
- Rollback: atomically restored the original operations table, including completed
  taxonomy history, and retained the compact copy. Nothing was dropped.
- Interrupted state A (live original + prepared compact, no old): resumed validation
  and atomic rename safely. State B (active compact + old): was recognized as Stage 1
  complete. State C (live + compact + old): aborted without dropping or renaming.
- Stage 2: validated and dropped only old; active compact rows, IDs, schema, indexes and
  FKs remained. A second Stage 2 was a no-op. Rollback after Stage 2 was blocked.

### Measured small-fixture DDL timings

These measurements characterize DDL behavior only; they do not predict duration for
the production-sized tables:

| Operation | Local duration |
|---|---:|
| `TRUNCATE TABLE` | 40.204 ms |
| `CREATE TABLE ... LIKE` | 74.258 ms |
| copy preserved rows | 12.216 ms |
| atomic two-table `RENAME TABLE` | 52.758 ms |
| `DROP TABLE old` | 24.188 ms |
| complete guarded Stage 1 | 512.032 ms |
| complete guarded Stage 2 | 158.223 ms |

The small InnoDB fixture occupied minimum 16 KiB data extents, so
`information_schema` cannot demonstrate multi-gigabyte reclamation. It did confirm the
expected allocation shape: Stage 1 temporarily retained both the original operations
tablespace and only a compact replacement; it did not create a second attributes copy.
Stage 2 removed the old table/tablespace. `TRUNCATE` operated in place on the attributes
table, reset AUTO_INCREMENT behavior and did not use `OPTIMIZE`. The command contains no
`OPTIMIZE TABLE` statement.

### Regression result and verdict

The MySQL/MariaDB opt-in profile passed with 44 assertions. No Phase 5 algorithm change
was required; only the database-specific integration profile and this evidence were
added. There are no rehearsal blockers to deploying the guarded command. Deployment
does not authorize running either destructive stage; production backup, maintenance
ownership and the documented guards remain mandatory.

**READY FOR PRODUCTION DEPLOY: YES**

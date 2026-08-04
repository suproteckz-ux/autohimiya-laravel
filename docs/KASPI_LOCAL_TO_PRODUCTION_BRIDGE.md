# Kaspi Local To Production Bridge

## Architecture

The bridge keeps production isolated from browser automation and from the local database.

Production is the authoritative catalog and content database. The local `products` table is not used to decide what needs Kaspi enrichment, and local Paloma sync is not required for this workflow.

Production Laravel:

1. Exposes `GET /api/internal/kaspi-content/candidates`.
2. Evaluates the authoritative candidate rule against production data.
3. Returns only SKU, name, current Kaspi URL, content-presence flags, and manual protection state.

Local Windows Laravel:

1. Fetches candidates from production with the same bearer token.
2. For candidates with a known `kaspi_product_url`, opens that URL with local Playwright using `scripts/kaspi-product-page-collector.mjs`.
3. For candidates without a URL, resolves a public Kaspi product URL locally using `scripts/kaspi-search-url-resolver.mjs`, then opens it.
4. Parses the collected HTML with the existing `KaspiEnrichmentParser`.
5. Builds a versioned JSON payload with SKU, resolved Kaspi URL, content, image URLs, and `request_id`.
6. Validates the payload locally.
7. Sends it to production over HTTPS with a dedicated bearer token.
8. Records local execution/cache state in `kaspi_production_pushes` by SKU/request ID.

Production Laravel:

1. Accepts `POST /api/internal/kaspi-content/import`.
2. Authenticates `Authorization: Bearer <token>` with `hash_equals`.
3. Validates payload size, version, SKU, Kaspi URL, content limits, image URLs, and executable-looking content.
4. Uses `request_id` in `kaspi_import_receipts` for idempotency.
5. Finds exactly one product by normalized SKU.
6. Rechecks the current product state inside the import transaction.
7. Reuses `KaspiDraftPublisher` to publish only still-missing allowed Kaspi content.
8. Downloads images on production with strict host, redirect, private-IP, byte-size, and MIME validation.

The production endpoints do not use Node.js, Playwright, shell commands, local product IDs, or direct production database access from local.

## Candidate Rule

A production product needs Kaspi processing when:

- it has no product images; or
- its description is empty.

A product is skipped when it already has both:

- at least one product image; and
- a non-empty description.

Manual-content-protected products are excluded by default. Attributes alone do not force processing when photo and description already exist.

## Environment

Production `.env`:

```dotenv
KASPI_IMPORT_API_TOKEN=
KASPI_IMPORT_API_RATE_LIMIT=30
KASPI_IMPORT_PAYLOAD_MAX_BYTES=262144
KASPI_IMAGE_ALLOWED_HOSTS=resources.cdn-kaspi.kz,kaspi.kz
KASPI_IMAGE_CONNECT_TIMEOUT=5
KASPI_IMAGE_TIMEOUT=15
KASPI_IMAGE_MAX_BYTES=5242880
```

Local `.env`:

```dotenv
KASPI_PRODUCTION_API_URL=https://xn--80aesatk1az7g.kz/api/internal/kaspi-content/import
KASPI_PRODUCTION_CANDIDATES_URL=https://xn--80aesatk1az7g.kz/api/internal/kaspi-content/candidates
KASPI_PRODUCTION_API_TOKEN=
```

Generate a token locally with:

```bash
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

Store the generated value only in local and production `.env`; do not commit it.

## Payload

The bridge sends payload version `1`:

```json
{
  "version": 1,
  "request_id": "uuid",
  "collected_at": "2026-08-03T20:00:00+05:00",
  "sku": "aut_608",
  "kaspi_url": "https://kaspi.kz/shop/p/...",
  "content": {
    "name": "Product name",
    "description": "Product description",
    "attributes": [{"name": "Объем", "value": "1 л"}],
    "images": [{"url": "https://resources.cdn-kaspi.kz/...", "position": 1}]
  },
  "source": {
    "collector": "local-playwright",
    "parser_version": "1"
  }
}
```

Rejected input includes local paths, `data:` / base64 image URLs, non-HTTPS URLs, unsupported image hosts, private IP image hosts, oversized payloads, oversized images, SVG/HTML/executable image responses, and PHP/executable-looking string content.

## Commands

Dry-run one SKU locally:

```bash
php artisan kaspi:push-production --sku=aut_608 --dry-run --debug
```

Dry-run fetches production candidates, shows which production SKUs would be processed, resolves or uses the Kaspi URL, validates payloads, and skips the production import POST.

Send one SKU to production:

```bash
php artisan kaspi:push-production --sku=aut_608
```

Send a small explicit batch:

```bash
php artisan kaspi:push-production --limit=5
```

The command refuses to process the whole catalog unless `--sku` or `--limit` is provided. `--force` recollects/resends even when local state already has a success.

## Responses

Imported:

```json
{
  "ok": true,
  "status": "imported",
  "request_id": "...",
  "sku": "aut_608",
  "result": {
    "name_updated": false,
    "description_updated": true,
    "attributes_updated": 12,
    "images_imported": 5
  }
}
```

Unchanged:

```json
{"ok": true, "status": "unchanged", "request_id": "...", "sku": "aut_608"}
```

Errors include `unauthorized`, `validation_failed`, `product_not_found`, `duplicate_sku_conflict`, `manual_content_protected`, and `import_failed`.

## Image Security

Production downloads only HTTPS images from the configured allowlist. Redirect targets are validated, localhost/private/link-local IP targets are blocked, response bodies are bounded, and MIME is verified from bytes. Images are staged in temporary storage first; failed batches are cleaned and do not create partial image records.

## One-SKU Test Procedure

1. Deploy code and run the listed migrations.
2. Set production `KASPI_IMPORT_API_TOKEN`.
3. Set local `KASPI_PRODUCTION_API_URL`, `KASPI_PRODUCTION_CANDIDATES_URL`, and `KASPI_PRODUCTION_API_TOKEN`.
4. Confirm one production SKU is returned by the candidates endpoint because it has no photo or an empty description.
5. Run:

```bash
php artisan kaspi:push-production --sku=aut_608 --dry-run --debug
```

6. If payload validation passes, run:

```bash
php artisan kaspi:push-production --sku=aut_608
```

7. Check the local command table and production product content. Price, quantity, stock, availability, category, brand, SKU, Paloma data, and orders must remain unchanged.

## Production Deployment

Deployment requires uploading the changed code and running migrations:

```bash
php artisan migrate --force
php artisan optimize:clear
```

Do not run Kaspi Playwright commands on production. Plesk Scheduler is not required for this bridge.

## Token Rotation

1. Generate a new token.
2. Update production `KASPI_IMPORT_API_TOKEN`.
3. Update local `KASPI_PRODUCTION_API_TOKEN`.
4. Run one dry-run and one one-SKU push.
5. Remove the old token from any private notes.

## Rollback

1. Disable the endpoint by clearing `KASPI_IMPORT_API_TOKEN` on production.
2. Revert the deployed code.
3. Run rollback migrations only if the new receipt/local-push tables must be removed.
4. Existing product content can be reviewed through `kaspi_publish_logs`, `kaspi_enrichment_tasks`, and product image records.

## Troubleshooting

- `401 unauthorized`: token missing or mismatched.
- `422 validation_failed`: inspect field names in the response; the token and body HTML are never returned.
- `404 product_not_found`: SKU normalization found no production product.
- `409 duplicate_sku_conflict`: more than one product matched the normalized SKU.
- `409 manual_content_protected`: manual content flags blocked the update.
- Local `network failed`: collected payload remains in `kaspi_production_pushes` and can be retried without rerunning Playwright.

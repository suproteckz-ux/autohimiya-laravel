# Homepage Product Image Alignment Report

## Scope

Fixed only homepage product-card image alignment CSS. No image files, thumbnail generation, product data, homepage grid column counts, category-card design, deployment, production, Plesk, or Git push were changed.

## Conflicting Selectors Found

Audit command:

`rg -n "home-products|home-products-grid|product-grid|product-card__image|product-card__media|product-card|image-fit-product|product-image" resources\css resources\views`

Relevant conflicts in `resources/css/storefront.css`:

- `.home-products .product-grid` at the desktop and responsive sections: homepage-only grid column rules. Kept because the request says not to change homepage grid column counts.
- `.home-products .product-card .product-image`: removed from the final polish layer because it competed with the shared product-card image container rule.
- `.home-products .product-card .product-image img`: removed from the final polish layer because it competed with the shared product image rule.
- `.product-grid .product-card .product-image`: removed from the final polish layer as part of eliminating the extra competing layer; the canonical shared rule is now the base `.product-image` rule in the existing final catalog/product-grid stabilization section.
- `.product-grid .product-card .image-fit-product` and `.home-products .product-card .image-fit-product`: removed from the final polish layer; the canonical shared rule is now `.product-image img, .image-fit-product`.
- `.product-card__image`, `.product-card__media`, `.home-products-grid`: no matches in the current repo.

## Computed Styles Before

The previous committed CSS computed the same numeric variable value, but from different selectors:

Category/catalog product cards:

- card min-height: `392px` from `.product-card`
- media flex/height/min-height: `var(--product-card-image-area)` from `.product-grid .product-card .product-image`
- media padding: `12px` from `.product-image`
- image width/height: `100%` from `.product-grid .product-card .product-image img`
- image object-fit/object-position: `contain` / `center` from `.product-grid .product-card .product-image img`

Homepage “Новинки” and “Хиты продаж” cards:

- card min-height: `392px` from `.product-card`
- media flex/height/min-height: `var(--product-card-image-area)` from `.home-products .product-card .product-image`
- media padding: `12px` from `.product-image`
- image width/height: `100%` from `.home-products .product-card .product-image img`
- image object-fit/object-position: `contain` / `center` from `.home-products .product-card .product-image img`

That meant homepage cards still had a separate selector path for the image container and image rules.

## Computed Styles After

Static CSS cascade verification used the same product-card DOM in three contexts:

1. category page context: `.product-grid .product-card`
2. homepage “Новинки” context: `.home-products .product-grid .product-card`
3. homepage “Хиты продаж” context: `.home-products #hits .product-grid .product-card`

All three now compute from the same shared selectors:

Card:

- min-height: `392px` from `.product-card`
- height: `100%` from `.product-card`
- padding: `12px` from `.product-card`
- display: `flex` from `.product-card`

Image container:

- flex: `0 0 188px` from `.product-image`
- width: `188px` from `.product-image`
- max-width: `100%` from `.product-image`
- height: `188px` from `.product-image`
- min-height: `188px` from `.product-image`
- aspect-ratio: `1 / 1` from `.product-image`
- padding: `12px` from `.product-image`
- display: `grid` from `.product-image`
- place-items: `center` from `.product-image`
- align-self: `center` from `.product-image`

Image:

- width: `100%` from `.product-image img`
- height: `100%` from `.product-image img`
- max-width: `100%` from `.product-image img`
- max-height: `100%` from `.product-image img`
- object-fit: `contain` from `.product-image img`
- object-position: `center` from `.product-image img`

Mobile shared rule remains shared as well:

- `.product-image` at `max-width: 640px` now sets `flex-basis`, `width`, `height`, and `min-height` to `168px` for every product card context.

## Verification Notes

The checkout does not contain `database/database.sqlite`, so the local Laravel homepage/category pages returned 500 when opened through the local server. I did not create or alter product data. Browser `data:` fixture navigation was blocked by the in-app browser URL policy, so no browser-policy workaround was attempted.

Instead, verification used a static CSS cascade calculation against the real `resources/css/storefront.css` and the real product-card class structure. It verified the same product-card DOM under category, “Новинки”, and “Хиты продаж” contexts.

## Tests

`php artisan test` passed with local env overrides:

- 17 tests
- 558 assertions
- warnings only from the missing local `.env`

## Files Changed

- `resources/css/storefront.css`
- `docs/HOMEPAGE_PRODUCT_IMAGE_ALIGNMENT_REPORT.md`
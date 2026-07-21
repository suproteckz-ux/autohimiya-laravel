# Product Image Admin UX Report

## Scope

Improved only the local Filament product image editor UX and primary-image calculation. No storage symlink, deployment, production update, or migration was performed.

## Changes

In `ProductResource` photo repeater:

- preview height reduced from 180 to 120 for a more compact editor;
- repeater items use a responsive grid (`md: 2`, `xl: 3`) so more images are visible at once;
- `sort_order` is now visible and numeric instead of hidden;
- primary flag label/helper text clarifies that only one image remains primary after save;
- existing upload rules, disk, directory, accepted types, download/open behavior, and relationship storage were preserved.

In `EditProduct::afterSave()`:

- when images are present in submitted data, `ProductImage::syncProductPrimaryImage()` is called;
- the product is refreshed before marking `photos_are_manual`;
- existing manual-photo protection behavior remains.

## Data Safety

No image table migration was added. Existing fields are used:

- `path`
- `card_thumb_path`
- `is_primary`
- `role`
- `sort_order`
- `source`
- `original_path`
- `original_name`

`ProductImage` model hooks still enforce one primary image and update legacy `products.primary_image` for storefront compatibility.

## Validation

`ProductImagePrimarySyncTest` confirms:

- when no image is flagged primary, the lowest `sort_order` image becomes primary;
- setting a second image primary clears the previous primary and updates `products.primary_image`.

Full test suite passed: 17 tests / 558 assertions. Warnings are from missing local `.env` only.

## Production Migration Requirement

No migration was added. Production does not require a database migration for this fix.
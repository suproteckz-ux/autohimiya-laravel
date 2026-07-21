# Product Bulk Category Fix Report

## Scope

Fixed only local Filament product bulk category assignment behavior. No production, Plesk, deployment, push, or server migration actions were performed.

## Root Cause

The Filament bulk action called `DefaultCategoryResolver::getOrCreateNewProductsCategoryId()` statically, but the resolver method is declared as an instance method. That can fail the bulk action at runtime on PHP 8+.

## Implementation

Added `App\Services\Catalog\ProductBulkCategoryAssigner` and changed the Filament bulk action to call it.

The service:

- validates that the selected category exists and is active;
- runs updates inside a DB transaction;
- updates `products.category_id`;
- marks `products.category_is_manual = true`;
- keeps the selected category in `category_product` through `syncWithoutDetaching()`;
- removes the default “new products” pivot category when assigning to another category;
- returns the updated record count.

The Filament action now deselects records after completion.

## Compatibility

No new database fields were introduced. Existing fields and pivot table are used:

- `products.category_id`
- `products.category_is_manual`
- `category_product`

## Validation

`ProductBulkCategoryAssignerTest` confirms that bulk assignment updates primary category, marks the category as manual, adds the selected pivot category, and removes the default pivot category.

Full test suite passed: 17 tests / 558 assertions. Warnings are from missing local `.env` only.

## Production Migration Requirement

No migration was added. Production does not require a database migration for this fix.
<?php

namespace App\Services\Ozon;

use App\Enums\OzonProductStatus;
use App\Models\OzonAccount;
use App\Models\OzonProduct;
use App\Models\OzonWarehouse;
use App\Models\Product;
use App\Services\Ozon\DTO\OzonPreparedProduct;
use Illuminate\Support\Facades\DB;
use Throwable;

class OzonProductPreparationService
{
    public function __construct(private readonly OzonPriceCalculator $prices, private readonly OzonStockCalculator $stocks, private readonly OzonDescriptionBuilder $descriptions, private readonly OzonImageService $images, private readonly OzonProductValidationService $validation) {}

    public function prepare(Product $product, array $settings): OzonPreparedProduct
    {
        $product->loadMissing(['brand', 'category', 'attributes', 'images']);
        $account = OzonAccount::query()->findOrFail($settings['ozon_account_id']);
        try { $price = $this->prices->calculate($product, $account, null, $settings['price_multiplier'] ?? null, $settings['rounding_rule'] ?? null); }
        catch (Throwable $e) { $price = null; }
        $stock = $this->stocks->calculate($product, isset($settings['stock_limit']) ? (int) $settings['stock_limit'] : $account->default_stock_limit);
        $description = $this->descriptions->build($product);
        $images = $this->images->resolve($product);
        $result = $this->validation->validate($product, $settings, $images, $description, $stock);
        if ($price === null) $result = new \App\Services\Ozon\DTO\OzonValidationResult([...$result->errors, 'Не удалось рассчитать положительную цену.'], $result->warnings);
        $attributes = $product->attributes->map(fn ($a) => ['name' => $a->name, 'value' => $a->value, 'unit' => $a->unit])->values()->all();
        $snapshot = ['product_id' => $product->id, 'source_name' => $product->name, 'prepared_name' => trim((string) $product->name), 'sku' => $product->sku, 'site_category_id' => $product->category_id, 'site_category_name' => $product->category?->name, 'description_category_id' => (string) ($settings['description_category_id'] ?? ''), 'description_category_name' => $settings['description_category_name'] ?? null, 'type_id' => (string) ($settings['type_id'] ?? ''), 'type_name' => $settings['type_name'] ?? null, 'prepared_description' => $description, 'prepared_images' => $images->urls, 'prepared_attributes' => $attributes, 'source_price' => $product->price, 'calculated_price' => $price, 'source_stock' => $product->quantity, 'calculated_stock' => $stock, 'weight_g' => $settings['weight_g'] ?? null, 'width_mm' => $settings['width_mm'] ?? null, 'height_mm' => $settings['height_mm'] ?? null, 'depth_mm' => $settings['depth_mm'] ?? null, 'tnved_code' => $settings['tnved_code'] ?? null];
        return new OzonPreparedProduct($product, $snapshot, $result);
    }

    public function save(OzonPreparedProduct $prepared, array $settings): ?OzonProduct
    {
        if (! $prepared->validation->isReady()) return null;
        return DB::transaction(function () use ($prepared, $settings): OzonProduct {
            $warehouseId = $settings['ozon_warehouse_id'] ?? null;
            if (! $warehouseId && filled($settings['manual_warehouse_id'] ?? null)) {
                $warehouseId = OzonWarehouse::query()->firstOrCreate(['ozon_account_id' => $settings['ozon_account_id'], 'ozon_warehouse_id' => (string) $settings['manual_warehouse_id']], ['name' => $settings['manual_warehouse_name'] ?: 'Ручной склад', 'status' => 'manual_unverified', 'is_active' => true])->id;
            }
            $data = $prepared->snapshot;
            return OzonProduct::query()->create(['ozon_account_id' => $settings['ozon_account_id'], 'product_id' => $prepared->product->id, 'site_category_id' => $data['site_category_id'], 'ozon_warehouse_id' => $warehouseId, 'offer_id' => $prepared->product->sku, 'description_category_id' => $data['description_category_id'], 'description_category_name' => $data['description_category_name'], 'type_id' => $data['type_id'], 'type_name' => $data['type_name'], 'status' => $prepared->validation->hasWarnings() ? OzonProductStatus::Draft : OzonProductStatus::Ready, 'prepared_name' => $data['prepared_name'], 'prepared_description' => $data['prepared_description'], 'prepared_images' => $data['prepared_images'], 'prepared_attributes' => $data['prepared_attributes'], 'prepared_payload' => $prepared->toArray(), 'price_sync_enabled' => (bool) ($settings['price_sync_enabled'] ?? true), 'stock_sync_enabled' => (bool) ($settings['stock_sync_enabled'] ?? true), 'price_multiplier' => $settings['price_multiplier'] ?? null, 'rounding_rule' => $settings['rounding_rule'] ?? null, 'stock_limit' => $settings['stock_limit'] ?? null, 'weight_g' => $data['weight_g'], 'width_mm' => $data['width_mm'], 'height_mm' => $data['height_mm'], 'depth_mm' => $data['depth_mm'], 'tnved_code' => $data['tnved_code'], 'calculated_price' => $data['calculated_price'], 'calculated_stock' => $data['calculated_stock']]);
        });
    }

    public function prepareBatch(iterable $products, array $settings, bool $save = false): array
    {
        $rows = []; foreach ($products as $product) { $prepared = $this->prepare($product, $settings); $stored = $save ? $this->save($prepared, $settings) : null; $rows[] = ['preview' => $prepared, 'saved' => $stored]; }
        return $rows;
    }

    public function deleteIfLocal(OzonProduct $product): bool
    {
        if (! in_array($product->status, [OzonProductStatus::Draft, OzonProductStatus::Ready, OzonProductStatus::Failed], true)) return false;
        return (bool) $product->delete();
    }
}

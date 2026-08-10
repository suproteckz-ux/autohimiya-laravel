<?php

namespace App\Services\Ozon;

use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\OzonProduct;
use App\Models\OzonTaxonomyAttribute;
use App\Models\OzonTaxonomyNode;
use Illuminate\Validation\ValidationException;
use Throwable;

class OzonAnnotationAttributeResolver
{
    private const ERROR_MESSAGE = 'Для выбранной категории Ozon не удалось определить характеристику «Аннотация».';

    public function __construct(private readonly OzonApiClient $client) {}

    public function resolve(OzonProduct $product, AutomationRun $run): OzonTaxonomyAttribute
    {
        if ($cached = $this->findCached($product)) {
            return $cached;
        }

        $node = OzonTaxonomyNode::query()
            ->where('ozon_account_id', $product->ozon_account_id)
            ->where('description_category_id', (string) $product->description_category_id)
            ->where('type_id', (string) $product->type_id)
            ->first();

        if (! $node) {
            $this->fail();
        }

        try {
            $response = $this->client->post($product->account, '/v1/description-category/attribute', [
                'description_category_id' => (int) $product->description_category_id,
                'type_id' => (int) $product->type_id,
                'language' => 'DEFAULT',
            ], OzonOperationType::TaxonomySync, $run);
        } catch (Throwable) {
            $this->fail();
        }

        $metadata = collect($response['result'] ?? [])->first(
            fn (mixed $attribute): bool => is_array($attribute) && $this->isAnnotationName($attribute['name'] ?? null),
        );
        $attributeId = is_array($metadata) ? (string) ($metadata['id'] ?? $metadata['attribute_id'] ?? '') : '';

        if ($attributeId === '') {
            $this->fail();
        }

        return OzonTaxonomyAttribute::query()->updateOrCreate(
            ['ozon_taxonomy_node_id' => $node->id, 'attribute_id' => $attributeId],
            [
                'name' => (string) ($metadata['name'] ?? ''),
                'type' => $metadata['type'] ?? null,
                'dictionary_id' => (string) ($metadata['dictionary_id'] ?? ''),
                'is_required' => (bool) ($metadata['is_required'] ?? false),
                'is_collection' => (bool) ($metadata['is_collection'] ?? false),
                'values_payload' => null,
                'raw_payload' => $metadata,
                'synced_at' => now(),
            ],
        );
    }

    public function findCached(OzonProduct $product): ?OzonTaxonomyAttribute
    {
        return OzonTaxonomyAttribute::query()
            ->whereHas('node', fn ($query) => $query
                ->where('ozon_account_id', $product->ozon_account_id)
                ->where('description_category_id', (string) $product->description_category_id)
                ->where('type_id', (string) $product->type_id))
            ->get()
            ->first(fn (OzonTaxonomyAttribute $attribute): bool => $this->isAnnotationName($attribute->name));
    }

    private function isAnnotationName(mixed $name): bool
    {
        return in_array(mb_strtolower(trim((string) $name)), ['аннотация', 'описание товара'], true);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['ozon_product' => self::ERROR_MESSAGE]);
    }
}

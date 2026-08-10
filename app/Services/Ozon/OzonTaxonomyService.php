<?php

namespace App\Services\Ozon;

use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyAttribute;
use App\Models\OzonTaxonomyNode;
use Illuminate\Support\Facades\DB;
use Throwable;

class OzonTaxonomyService
{
    public function __construct(private readonly OzonApiClient $client) {}

    public function syncTree(OzonAccount $account, ?AutomationRun $run = null): array
    {
        $response = $this->client->post($account, '/v1/description-category/tree', ['language' => 'DEFAULT'], OzonOperationType::TaxonomySync, $run);

        if (! array_key_exists('result', $response) || ! is_array($response['result'])) {
            throw new \UnexpectedValueException('Ozon taxonomy response has invalid schema.');
        }

        $count = DB::transaction(fn (): int => $this->storeNodes($account, $response['result'], null));

        return ['successful' => true, 'processed_items' => $count];
    }

    public function syncAttributes(OzonTaxonomyNode $node, ?AutomationRun $run = null): array
    {
        $response = $this->client->post($node->account, '/v1/description-category/attribute', [
            'description_category_id' => (int) $node->description_category_id,
            'type_id' => (int) $node->type_id,
            'language' => 'DEFAULT',
        ], OzonOperationType::TaxonomySync, $run);

        if (! array_key_exists('result', $response) || ! is_array($response['result'])) {
            throw new \UnexpectedValueException('Ozon attribute response has invalid schema.');
        }

        $saved = 0;
        $dictionaryValuesLoaded = 0;
        $warnings = [];

        foreach ($response['result'] as $item) {
            $attributeId = (string) ($item['id'] ?? '');

            if ($attributeId === '') {
                $warnings[] = "Type {$node->type_id}: attribute without id skipped.";

                continue;
            }

            $dictionaryId = (string) ($item['dictionary_id'] ?? '');
            $existing = OzonTaxonomyAttribute::query()
                ->where('ozon_taxonomy_node_id', $node->id)
                ->where('attribute_id', $attributeId)
                ->first();
            $values = $existing?->values_payload;

            if ((int) $dictionaryId > 0) {
                try {
                    $values = $this->loadValues($node, $attributeId, $run);
                    $dictionaryValuesLoaded += count($values);
                } catch (Throwable $exception) {
                    $warnings[] = "Type {$node->type_id}, attribute {$attributeId}: dictionary values were not loaded ({$this->safeError($exception)}).";
                }
            } else {
                $values = null;
            }

            OzonTaxonomyAttribute::query()->updateOrCreate(
                ['ozon_taxonomy_node_id' => $node->id, 'attribute_id' => $attributeId],
                [
                    'name' => (string) ($item['name'] ?? ''),
                    'type' => $item['type'] ?? null,
                    'dictionary_id' => $dictionaryId,
                    'is_required' => (bool) ($item['is_required'] ?? false),
                    'is_collection' => (bool) ($item['is_collection'] ?? false),
                    'values_payload' => $values,
                    'raw_payload' => $item,
                    'synced_at' => now(),
                ],
            );
            $saved++;
        }

        return [
            'successful' => true,
            'processed_items' => $saved,
            'attributes_saved' => $saved,
            'dictionary_values_loaded' => $dictionaryValuesLoaded,
            'warnings' => $warnings,
        ];
    }

    public function syncAllAttributes(OzonAccount $account, ?AutomationRun $run = null): array
    {
        $query = OzonTaxonomyNode::query()
            ->where('ozon_account_id', $account->id)
            ->where('is_disabled', false)
            ->whereNotNull('type_id')
            ->where('type_id', '!=', '')
            ->where('type_id', '!=', '0');

        $stats = [
            'successful' => true,
            'processed_items' => 0,
            'type_nodes_total' => (clone $query)->count(),
            'type_nodes_processed' => 0,
            'attributes_saved' => 0,
            'dictionary_values_loaded' => 0,
            'failed_nodes' => 0,
            'warnings' => [],
        ];

        $query->orderBy('id')->chunkById(100, function ($nodes) use (&$stats, $run): void {
            foreach ($nodes as $node) {
                $stats['type_nodes_processed']++;

                try {
                    $result = $this->syncAttributes($node, $run);
                    $stats['processed_items'] += (int) $result['processed_items'];
                    $stats['attributes_saved'] += (int) $result['attributes_saved'];
                    $stats['dictionary_values_loaded'] += (int) $result['dictionary_values_loaded'];
                    $stats['warnings'] = [...$stats['warnings'], ...$result['warnings']];
                } catch (Throwable $exception) {
                    $stats['failed_nodes']++;
                    $stats['warnings'][] = "Type {$node->type_id}: attributes were not loaded ({$this->safeError($exception)}).";
                }
            }
        });

        return $stats;
    }

    private function loadValues(OzonTaxonomyNode $node, string $attributeId, ?AutomationRun $run): array
    {
        $last = 0;
        $values = [];

        do {
            $response = $this->client->post($node->account, '/v1/description-category/attribute/values', [
                'description_category_id' => (int) $node->description_category_id,
                'type_id' => (int) $node->type_id,
                'attribute_id' => (int) $attributeId,
                'language' => 'DEFAULT',
                'limit' => 5000,
                'last_value_id' => $last,
            ], OzonOperationType::TaxonomySync, $run);

            if (! array_key_exists('result', $response) || ! is_array($response['result'])) {
                throw new \UnexpectedValueException('Ozon attribute values response has invalid schema.');
            }

            $items = $response['result'];
            $values = array_merge($values, $items);
            $last = (int) ($response['last_value_id'] ?? 0);
        } while ($last > 0 && $items !== []);

        return $values;
    }

    private function storeNodes(OzonAccount $account, array $items, ?OzonTaxonomyNode $parent): int
    {
        $count = 0;

        foreach ($items as $item) {
            $typeId = (string) ($item['type_id'] ?? '0');
            $hasType = (int) $typeId > 0;
            $categoryId = (string) ($item['description_category_id'] ?? ($hasType ? $parent?->description_category_id : ''));
            $categoryName = (string) ($item['category_name'] ?? ($hasType ? $parent?->category_name : $categoryId));

            if ($categoryId === '') {
                $count += $this->storeNodes($account, (array) ($item['children'] ?? []), $parent);

                continue;
            }

            $node = OzonTaxonomyNode::query()->updateOrCreate(
                ['ozon_account_id' => $account->id, 'description_category_id' => $categoryId, 'type_id' => $typeId],
                [
                    'parent_id' => $parent?->id,
                    'category_name' => $categoryName,
                    'type_name' => (string) ($item['type_name'] ?? ''),
                    'is_disabled' => (bool) ($item['disabled'] ?? false),
                    'raw_payload' => $item,
                    'synced_at' => now(),
                ],
            );
            $count++;
            $count += $this->storeNodes($account, (array) ($item['children'] ?? []), $node);
        }

        return $count;
    }

    private function safeError(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 300);
    }
}

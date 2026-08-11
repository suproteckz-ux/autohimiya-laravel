<?php

namespace App\Services\Ozon;

use App\Enums\OzonOperationType;
use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyAttribute;
use App\Models\OzonTaxonomyNode;
use App\Services\Automation\AutomationProgressReporterInterface;
use Illuminate\Database\Eloquent\Builder;
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
        $warnings = [];

        foreach ($response['result'] as $item) {
            $attributeId = (string) ($item['id'] ?? '');

            if ($attributeId === '') {
                $warnings[] = "Type {$node->type_id}: attribute without id skipped.";

                continue;
            }

            $dictionaryId = (string) ($item['dictionary_id'] ?? '');

            OzonTaxonomyAttribute::query()->updateOrCreate(
                ['ozon_taxonomy_node_id' => $node->id, 'attribute_id' => $attributeId],
                [
                    'name' => (string) ($item['name'] ?? ''),
                    'type' => $item['type'] ?? null,
                    'dictionary_id' => $dictionaryId,
                    'is_required' => (bool) ($item['is_required'] ?? false),
                    'is_collection' => (bool) ($item['is_collection'] ?? false),
                    'values_payload' => null,
                    'raw_payload' => null,
                    'synced_at' => now(),
                ],
            );
            $saved++;
        }

        return [
            'successful' => true,
            'processed_items' => $saved,
            'attributes_saved' => $saved,
            'dictionary_values_loaded' => 0,
            'warnings' => $warnings,
        ];
    }

    public function syncAllAttributes(OzonAccount $account, ?AutomationRun $run = null): array
    {
        $query = $this->activeTypeNodes($account);

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

    public function syncAttributeBatch(
        OzonAccount $account,
        int $afterNodeId,
        int $limit,
        ?AutomationRun $run = null,
        ?AutomationProgressReporterInterface $progress = null,
        int $maxSeconds = 240,
    ): array {
        $limit = max(1, min(50, $limit));
        $startedAt = microtime(true);
        $baseQuery = $this->activeTypeNodes($account);
        $total = (clone $baseQuery)->count();
        $nodes = $baseQuery
            ->where('id', '>', max(0, $afterNodeId))
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        $hasMore = $nodes->count() > $limit;
        $nodes = $nodes->take($limit);
        $stats = [
            'successful' => true,
            'processed_items' => 0,
            'type_nodes_total' => $total,
            'type_nodes_processed' => 0,
            'attributes_saved' => 0,
            'dictionary_values_loaded' => 0,
            'failed_nodes' => 0,
            'warnings' => [],
            'last_processed_node_id' => $afterNodeId,
            'has_more' => $hasMore,
        ];

        foreach ($nodes as $node) {
            if ($stats['type_nodes_processed'] > 0 && microtime(true) - $startedAt >= max(1, $maxSeconds)) {
                $stats['has_more'] = true;

                break;
            }

            try {
                $result = $this->syncAttributes($node, $run);
                $stats['processed_items'] += (int) $result['processed_items'];
                $stats['attributes_saved'] += (int) $result['attributes_saved'];
                $stats['dictionary_values_loaded'] += (int) $result['dictionary_values_loaded'];
                $stats['warnings'] = [...$stats['warnings'], ...$result['warnings']];
            } catch (Throwable $exception) {
                $stats['failed_nodes']++;
                $stats['warnings'][] = "Type {$node->type_id}: attributes were not loaded ({$this->safeError($exception)}).";
            } finally {
                $stats['type_nodes_processed']++;
                $stats['last_processed_node_id'] = $node->id;
                $progress?->heartbeat("Taxonomy Ozon: обработан type node {$node->id}.");
            }
        }

        return $stats;
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

    private function activeTypeNodes(OzonAccount $account): Builder
    {
        return OzonTaxonomyNode::query()
            ->where('ozon_account_id', $account->id)
            ->where('is_disabled', false)
            ->whereNotNull('type_id')
            ->where('type_id', '!=', '')
            ->where('type_id', '!=', '0');
    }

    public function nodesAreFresh(OzonAccount $account, int $hours = 24): bool
    {
        return OzonTaxonomyNode::query()
            ->where('ozon_account_id', $account->id)
            ->where('synced_at', '>=', now()->subHours(max(1, $hours)))
            ->exists();
    }
}

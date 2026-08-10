<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyNode;
use App\Services\Automation\AutomationHandlerInterface;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Ozon\OzonTaxonomyService;

class OzonTaxonomySyncHandler implements AutomationHandlerInterface
{
    public function __construct(private readonly OzonTaxonomyService $service) {}

    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array
    {
        $progress->start(1, 'Загрузка taxonomy Ozon.');
        $nodeId = (int) ($run->context['ozon_taxonomy_node_id'] ?? 0);

        if ($nodeId) {
            $attributes = $this->service->syncAttributes(OzonTaxonomyNode::query()->findOrFail($nodeId), $run);
            $result = [
                ...$attributes,
                'type_nodes_total' => 1,
                'type_nodes_processed' => 1,
                'failed_nodes' => 0,
            ];
        } else {
            $account = OzonAccount::query()->findOrFail((int) ($run->context['ozon_account_id'] ?? 0));
            $tree = $this->service->syncTree($account, $run);
            $attributes = $this->service->syncAllAttributes($account, $run);
            $result = [
                ...$attributes,
                'processed_items' => (int) $tree['processed_items'] + (int) $attributes['processed_items'],
                'processed_nodes' => (int) $tree['processed_items'],
            ];
        }

        $processed = (int) $result['processed_items'];
        $warnings = $result['warnings'] ?? [];
        $progress->setProgress($processed, $processed, 'Taxonomy Ozon загружена.');
        $summary = sprintf(
            'Taxonomy Ozon загружена: type nodes %d/%d, attributes %d, dictionary values %d, failed nodes %d, warnings %d.',
            (int) ($result['type_nodes_processed'] ?? 0),
            (int) ($result['type_nodes_total'] ?? 0),
            (int) ($result['attributes_saved'] ?? 0),
            (int) ($result['dictionary_values_loaded'] ?? 0),
            (int) ($result['failed_nodes'] ?? 0),
            count($warnings),
        );

        return [
            ...$result,
            'total_items' => $processed,
            'failed_count' => (int) ($result['failed_nodes'] ?? 0),
            'warnings' => $warnings,
            'message' => $summary,
        ];
    }
}

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
        $nodeId = (int) ($run->context['ozon_taxonomy_node_id'] ?? 0);

        if ($nodeId > 0) {
            $progress->start(1, 'Loading attributes required for one Ozon type node.');
            $attributes = $this->service->syncAttributes(OzonTaxonomyNode::query()->findOrFail($nodeId), $run);
            $progress->setProgress(1, 1, 'Ozon type-node attributes loaded without dictionary values.');

            return [
                ...$attributes,
                'type_nodes_total' => 1,
                'type_nodes_processed' => 1,
                'failed_nodes' => 0,
                'total_items' => 1,
                'failed_count' => 0,
                'message' => 'Ozon type-node attributes loaded without dictionary values.',
            ];
        }

        $context = $run->context ?? [];
        $account = OzonAccount::query()->findOrFail((int) ($context['ozon_account_id'] ?? 0));
        $forceNodes = (bool) ($context['force_nodes_sync'] ?? false);
        $treeNodes = 0;

        if ($forceNodes || ! $this->service->nodesAreFresh($account)) {
            $progress->start(1, 'Loading Ozon category/type nodes.');
            $treeNodes = (int) $this->service->syncTree($account, $run)['processed_items'];
            $progress->setProgress(1, 1, 'Ozon category/type nodes loaded.');
        }

        return [
            'successful' => true,
            'processed_items' => $treeNodes,
            'processed_tree_nodes' => $treeNodes,
            'attributes_saved' => 0,
            'dictionary_values_loaded' => 0,
            'total_items' => $treeNodes,
            'failed_count' => 0,
            'message' => $treeNodes > 0
                ? 'Taxonomy nodes Ozon loaded. Attributes are resolved on demand.'
                : 'Taxonomy nodes Ozon are fresh. Repeated sync was skipped.',
        ];
    }
}

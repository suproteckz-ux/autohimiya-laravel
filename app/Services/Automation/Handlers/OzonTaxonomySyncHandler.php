<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationRun;
use App\Models\OzonAccount;
use App\Models\OzonTaxonomyNode;
use App\Services\Automation\AutomationHandlerInterface;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Automation\AutomationRunService;
use App\Services\Ozon\OzonTaxonomyService;

class OzonTaxonomySyncHandler implements AutomationHandlerInterface
{
    public const BATCH_SIZE = 20;
    public const MAX_BATCH_SECONDS = 240;

    public function __construct(
        private readonly OzonTaxonomyService $service,
        private readonly AutomationRunService $runs,
    ) {}

    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array
    {
        $nodeId = (int) ($run->context['ozon_taxonomy_node_id'] ?? 0);

        if ($nodeId) {
            $progress->start(1, 'Загрузка характеристик type node Ozon.');
            $attributes = $this->service->syncAttributes(OzonTaxonomyNode::query()->findOrFail($nodeId), $run);
            $progress->setProgress(1, 1, 'Характеристики type node Ozon загружены.');

            return [
                ...$attributes,
                'type_nodes_total' => 1,
                'type_nodes_processed' => 1,
                'failed_nodes' => 0,
                'total_items' => 1,
                'failed_count' => 0,
                'message' => 'Характеристики type node Ozon загружены.',
            ];
        }

        $context = $run->context ?? [];
        $account = OzonAccount::query()->findOrFail((int) ($context['ozon_account_id'] ?? 0));
        $treeNodes = 0;

        if (! (bool) ($context['taxonomy_tree_synced'] ?? false)) {
            $treeNodes = (int) $this->service->syncTree($account, $run)['processed_items'];
            $context['taxonomy_tree_synced'] = true;
        }

        $progress->start(self::BATCH_SIZE, 'Загрузка batch характеристик taxonomy Ozon.');
        $batch = $this->service->syncAttributeBatch(
            $account,
            (int) ($context['last_processed_node_id'] ?? 0),
            self::BATCH_SIZE,
            $run,
            $progress,
            self::MAX_BATCH_SECONDS,
        );

        $state = [
            'ozon_account_id' => $account->id,
            'taxonomy_tree_synced' => true,
            'last_processed_node_id' => (int) $batch['last_processed_node_id'],
            'total_nodes' => (int) $batch['type_nodes_total'],
            'processed_nodes' => (int) ($context['processed_nodes'] ?? 0) + (int) $batch['type_nodes_processed'],
            'attributes_saved' => (int) ($context['attributes_saved'] ?? 0) + (int) $batch['attributes_saved'],
            'dictionary_values_loaded' => (int) ($context['dictionary_values_loaded'] ?? 0) + (int) $batch['dictionary_values_loaded'],
            'failed_nodes' => (int) ($context['failed_nodes'] ?? 0) + (int) $batch['failed_nodes'],
            'warnings' => array_slice([
                ...(array) ($context['warnings'] ?? []),
                ...(array) $batch['warnings'],
            ], -100),
        ];

        $run->forceFill(['context' => array_replace_recursive($context, $state)])->save();
        $continued = false;

        if ($batch['has_more']) {
            $continued = $this->runs->requestContinuation($run, $state)['created'];
        }

        $processedInBatch = (int) $batch['type_nodes_processed'];
        $progress->setProgress(self::BATCH_SIZE, $processedInBatch, $batch['has_more']
            ? 'Batch taxonomy Ozon завершён; продолжение поставлено в очередь.'
            : 'Taxonomy Ozon загружена полностью.');

        return [
            ...$batch,
            'processed_items' => $processedInBatch,
            'type_nodes_total' => $state['total_nodes'],
            'attributes_saved' => $state['attributes_saved'],
            'dictionary_values_loaded' => $state['dictionary_values_loaded'],
            'failed_nodes' => $state['failed_nodes'],
            'warnings' => $state['warnings'],
            'processed_nodes' => $state['processed_nodes'],
            'processed_tree_nodes' => $treeNodes,
            'continuation_created' => $continued,
            'total_items' => $processedInBatch,
            'failed_count' => (int) $batch['failed_nodes'],
            'message' => sprintf(
                'Taxonomy Ozon: type nodes %d/%d, attributes %d, dictionary values %d, failed nodes %d, warnings %d%s.',
                $state['processed_nodes'],
                $state['total_nodes'],
                $state['attributes_saved'],
                $state['dictionary_values_loaded'],
                $state['failed_nodes'],
                count($state['warnings']),
                $batch['has_more'] ? '; continuation pending' : '; completed',
            ),
        ];
    }
}

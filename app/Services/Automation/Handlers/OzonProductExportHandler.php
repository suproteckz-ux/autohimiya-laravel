<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationRun;
use App\Models\OzonProduct;
use App\Services\Automation\AutomationHandlerInterface;
use App\Services\Automation\AutomationProgressReporterInterface;
use App\Services\Ozon\OzonProductExportService;

class OzonProductExportHandler implements AutomationHandlerInterface
{
    public function __construct(private readonly OzonProductExportService $service) {}

    public function handle(AutomationRun $run, AutomationProgressReporterInterface $progress, bool $dryRun = false): array
    {
        $progress->start(1, 'Отправка одного товара в Ozon.');
        $product = OzonProduct::query()->findOrFail((int) ($run->context['ozon_product_id'] ?? 0));
        $result = $this->service->export($product, $run);
        $progress->setProgress(1, 1, 'Карточка отправлена в обработку Ozon.');
        return [...$result, 'total_items' => 1, 'processed_items' => 1, 'updated_count' => 1, 'message' => 'Карточка отправлена в обработку Ozon.'];
    }
}

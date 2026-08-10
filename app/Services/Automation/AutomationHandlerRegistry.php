<?php

namespace App\Services\Automation;

use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Services\Automation\Handlers\AutomationHealthHandler;
use App\Services\Automation\Handlers\CatalogQualityReportHandler;
use App\Services\Automation\Handlers\KaspiImportContentHandler;
use App\Services\Automation\Handlers\KaspiResolveWidgetUrlsHandler;
use App\Services\Automation\Handlers\PalomaSyncRemainsHandler;
use App\Services\Automation\Handlers\OzonConnectionCheckHandler;
use App\Services\Automation\Handlers\OzonTaxonomySyncHandler;
use App\Services\Automation\Handlers\OzonWarehouseSyncHandler;
use App\Services\Automation\Handlers\OzonProductExportHandler;
use App\Services\Automation\Handlers\OzonProductExportStatusHandler;
use InvalidArgumentException;

class AutomationHandlerRegistry
{
    /**
     * @return array<string, class-string<AutomationHandlerInterface>>
     */
    public function handlers(): array
    {
        return [
            AutomationType::PalomaSyncRemains->value => PalomaSyncRemainsHandler::class,
            AutomationType::KaspiResolveWidgetUrls->value => KaspiResolveWidgetUrlsHandler::class,
            AutomationType::KaspiImportContent->value => KaspiImportContentHandler::class,
            AutomationType::AutomationHealth->value => AutomationHealthHandler::class,
            AutomationType::CatalogQualityReport->value => CatalogQualityReportHandler::class,
            AutomationType::OzonConnectionCheck->value => OzonConnectionCheckHandler::class,
            AutomationType::OzonWarehouseSync->value => OzonWarehouseSyncHandler::class,
            AutomationType::OzonTaxonomySync->value => OzonTaxonomySyncHandler::class,
            AutomationType::OzonProductExport->value => OzonProductExportHandler::class,
            AutomationType::OzonProductExportStatus->value => OzonProductExportStatusHandler::class,
        ];
    }

    public function resolve(AutomationRun $run): AutomationHandlerInterface
    {
        $class = $this->handlers()[(string) $run->type] ?? null;

        if (! $class) {
            throw new InvalidArgumentException('Unsupported automation type: '.$run->type);
        }

        return app($class);
    }
}

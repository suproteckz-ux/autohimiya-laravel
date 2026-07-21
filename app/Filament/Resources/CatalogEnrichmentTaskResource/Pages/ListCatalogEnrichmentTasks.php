<?php

namespace App\Filament\Resources\CatalogEnrichmentTaskResource\Pages;

use App\Filament\Resources\CatalogEnrichmentTaskResource;
use Filament\Resources\Pages\ListRecords;

class ListCatalogEnrichmentTasks extends ListRecords
{
    protected static string $resource = CatalogEnrichmentTaskResource::class;

    public function getHeading(): string
    {
        return 'Техническая очередь задач';
    }

    public function getSubheading(): ?string
    {
        return 'Основная работа с контентом выполняется в Контент-центре. Этот экран оставлен для диагностики и контроля очереди.';
    }
}

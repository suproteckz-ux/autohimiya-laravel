<?php

namespace App\Enums;

enum AutomationType: string
{
    case PalomaSyncRemains = 'paloma_sync_remains';
    case KaspiResolveWidgetUrls = 'kaspi_resolve_widget_urls';
    case KaspiImportContent = 'kaspi_import_content';
    case AutomationHealth = 'automation_health';
    case CatalogQualityReport = 'catalog_quality_report';

    public function commandName(): string
    {
        return match ($this) {
            self::PalomaSyncRemains => 'paloma:sync-remains',
            self::KaspiResolveWidgetUrls => 'kaspi:resolve-widget-urls',
            self::KaspiImportContent => 'kaspi:import-content',
            self::AutomationHealth => 'automation:health',
            self::CatalogQualityReport => 'catalog:quality-report',
        };
    }

    public function handlerIdentifier(): string
    {
        return 'automation.handler.'.$this->value;
    }

    public function russianLabel(): string
    {
        return match ($this) {
            self::PalomaSyncRemains => 'Синхронизация Paloma',
            self::KaspiResolveWidgetUrls => 'Поиск URL Kaspi',
            self::KaspiImportContent => 'Импорт контента Kaspi',
            self::AutomationHealth => 'Проверка автоматизации',
            self::CatalogQualityReport => 'Отчет качества каталога',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultContext(): array
    {
        return match ($this) {
            self::PalomaSyncRemains => ['timeout' => 60],
            self::KaspiResolveWidgetUrls => [
                'limit' => 500,
                'headless' => true,
                'delay_ms' => 3000,
                'only_missing_url' => true,
            ],
            self::KaspiImportContent => [
                'limit' => 0,
                'only_missing' => true,
                'force' => false,
                'delay_ms' => 3000,
            ],
            self::AutomationHealth, self::CatalogQualityReport => [],
        };
    }

    public static function fromCommandName(string $command): ?self
    {
        return match ($command) {
            'paloma:sync-remains' => self::PalomaSyncRemains,
            'kaspi:resolve-widget-urls' => self::KaspiResolveWidgetUrls,
            'kaspi:import-content' => self::KaspiImportContent,
            'automation:health' => self::AutomationHealth,
            'catalog:quality-report' => self::CatalogQualityReport,
            default => null,
        };
    }
}
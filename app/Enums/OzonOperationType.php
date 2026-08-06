<?php

namespace App\Enums;

enum OzonOperationType: string
{
    case ConnectionCheck = 'connection_check';
    case WarehouseSync = 'warehouse_sync';
    case TaxonomySync = 'taxonomy_sync';
    case ProductPrepare = 'product_prepare';
    case ProductExport = 'product_export';
    case StatusCheck = 'status_check';
    case PriceUpdate = 'price_update';
    case StockUpdate = 'stock_update';
    case CommercialUpdate = 'commercial_update';
    case HealthCheck = 'health_check';
    case OperationPrune = 'operation_prune';

    public function label(): string
    {
        return match ($this) {
            self::ConnectionCheck => 'Проверка подключения',
            self::WarehouseSync => 'Синхронизация складов',
            self::TaxonomySync => 'Синхронизация категорий и типов',
            self::ProductPrepare => 'Подготовка товара',
            self::ProductExport => 'Выгрузка товара',
            self::StatusCheck => 'Проверка статуса',
            self::PriceUpdate => 'Обновление цены',
            self::StockUpdate => 'Обновление остатка',
            self::CommercialUpdate => 'Обновление цены и остатка',
            self::HealthCheck => 'Проверка интеграции',
            self::OperationPrune => 'Очистка журнала',
        };
    }
}

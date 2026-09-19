<?php

namespace App\Filament\Resources\KaspiOrderResource\Pages;

use App\Enums\KaspiOrderInternalStatus;
use App\Models\KaspiOrder;
use App\Filament\Resources\KaspiOrderResource;
use App\Services\Kaspi\KaspiOrdersClient;
use App\Services\Kaspi\KaspiStockCalculator;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Pages\ViewRecord;

class ViewKaspiOrder extends ViewRecord
{
    protected static string $resource = KaspiOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create_waybill')
                ->label('Сформировать накладную')
                ->icon('heroicon-o-document-text')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Сформировать накладную Kaspi')
                ->modalDescription('Будет выполнен запрос к Kaspi API для создания накладной. Статус заказа изменится на ASSEMBLE.')
                ->form([
                    TextInput::make('number_of_space')
                        ->label('Количество мест (numberOfSpace)')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->required(),
                ])
                ->visible(fn (KaspiOrder $record) => empty($record->waybill_number))
                ->action(function (array $data, KaspiOrder $record) {
                    try {
                        $client = app(KaspiOrdersClient::class);
                        $result = $client->createWaybill(
                            $record->kaspi_order_id,
                            $record->kaspi_code,
                            (int) ($data['number_of_space'] ?? 1)
                        );

                        $waybill = $result['data']['attributes']['waybill'] ?? null;
                        $waybillNumber = $result['data']['attributes']['waybillNumber'] ?? null;

                        $record->update([
                            'waybill' => $waybill,
                            'waybill_number' => $waybillNumber,
                            'number_of_space' => (int) ($data['number_of_space'] ?? 1),
                            'kaspi_status' => 'ASSEMBLE',
                        ]);

                        Notification::make()->title('Накладная создана')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Ошибка: ' . $e->getMessage())->danger()->send();
                    }
                }),

            Action::make('open_waybill')
                ->label('Открыть накладную')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('info')
                ->visible(fn (KaspiOrder $record) => ! empty($record->waybill))
                ->url(fn (KaspiOrder $record) => $record->waybill)
                ->openUrlInNewTab(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Позиции заказа')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->schema([
                                TextEntry::make('merchant_sku')->label('SKU'),
                                TextEntry::make('product.name')->label('Товар')->placeholder('— (unmatched)'),
                                TextEntry::make('qty')->label('Кол-во'),
                                TextEntry::make('unit_price')->label('Цена')->money('KZT'),
                                TextEntry::make('sku_match_status')
                                    ->label('SKU match')
                                    ->badge()
                                    ->color(fn (string $state) => $state === 'matched' ? 'success' : 'danger'),
                                TextEntry::make('product_id')
                                    ->label('Paloma stock')
                                    ->getStateUsing(function ($record) {
                                        if (! $record->product_id) {
                                            return '—';
                                        }
                                        return (string) $record->product?->quantity;
                                    }),
                            ])
                            ->columns(6),
                    ]),
            ]);
    }
}

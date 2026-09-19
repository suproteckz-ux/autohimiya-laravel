<?php

namespace App\Filament\Resources;

use App\Enums\KaspiOrderInternalStatus;
use App\Filament\Resources\KaspiOrderResource\Pages;
use App\Models\KaspiOrder;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use BackedEnum;
use UnitEnum;

class KaspiOrderResource extends Resource
{
    protected static ?string $model = KaspiOrder::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-shopping-bag';
    protected static string|UnitEnum|null $navigationGroup = 'Kaspi';
    protected static ?string $navigationLabel = 'Заказы';
    protected static ?string $modelLabel = 'Заказ Kaspi';
    protected static ?string $pluralModelLabel = 'Заказы Kaspi';
    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        $mode = config('services.kaspi.orders_mode', 'observe');
        $modeLabel = strtoupper($mode);

        return $table
            ->description("Режим: {$modeLabel}" . ($mode === 'observe' ? ' — резерв не влияет на stock feed' : ''))
            ->columns([
                TextColumn::make('kaspi_code')
                    ->label('Код заказа')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('kaspi_created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('kaspi_state')
                    ->label('State')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'KASPI_DELIVERY' => 'info',
                        'PICKUP' => 'warning',
                        'DELIVERY' => 'gray',
                        'ARCHIVE' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('kaspi_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $status): string => match ($status) {
                        'APPROVED_BY_BANK', 'ACCEPTED_BY_MERCHANT' => 'success',
                        'ASSEMBLE' => 'warning',
                        'COMPLETED' => 'success',
                        'CANCELLED', 'CANCELLING' => 'danger',
                        'KASPI_DELIVERY_RETURN_REQUESTED', 'RETURNED' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('internal_stock_status')
                    ->label('Внутренний статус')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof KaspiOrderInternalStatus ? $state->label() : (string) $state)
                    ->color(fn ($state): string => match (true) {
                        $state === KaspiOrderInternalStatus::Reserved => 'warning',
                        $state === KaspiOrderInternalStatus::HandoffCandidate => 'info',
                        $state === KaspiOrderInternalStatus::HandedOffPendingPaloma => 'danger',
                        $state === KaspiOrderInternalStatus::CancelledBeforeHandoff => 'success',
                        $state === KaspiOrderInternalStatus::CancelledAfterHandoff => 'gray',
                        $state === KaspiOrderInternalStatus::PalomaConfirmed => 'success',
                        $state === KaspiOrderInternalStatus::BaselineIgnored => 'gray',
                        $state === KaspiOrderInternalStatus::ErrorUnmatchedSku => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('items_count')
                    ->label('Позиций')
                    ->counts('items')
                    ->sortable(),

                TextColumn::make('items_sum_qty')
                    ->label('Кол-во')
                    ->sum('items', 'qty'),

                TextColumn::make('courier_transmission_date')
                    ->label('Передан курьеру')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('handoff_at')
                    ->label('Handoff')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->getStateUsing(fn (KaspiOrder $r) => $r->handoff_at !== null),

                Tables\Columns\IconColumn::make('baseline_ignored')
                    ->label('Baseline')
                    ->boolean(),

                TextColumn::make('waybill_number')
                    ->label('Накладная')
                    ->placeholder('—'),

                TextColumn::make('last_synced_at')
                    ->label('Sync')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('kaspi_created_at', 'desc')
            ->filters([
                SelectFilter::make('internal_stock_status')
                    ->label('Внутренний статус')
                    ->options(collect(KaspiOrderInternalStatus::cases())->mapWithKeys(
                        fn (KaspiOrderInternalStatus $s) => [$s->value => $s->label()]
                    )),

                SelectFilter::make('kaspi_state')
                    ->label('State')
                    ->options([
                        'NEW' => 'NEW',
                        'KASPI_DELIVERY' => 'KASPI_DELIVERY',
                        'PICKUP' => 'PICKUP',
                        'DELIVERY' => 'DELIVERY',
                        'ARCHIVE' => 'ARCHIVE',
                    ]),

                SelectFilter::make('kaspi_status')
                    ->label('Status')
                    ->options([
                        'APPROVED_BY_BANK' => 'APPROVED_BY_BANK',
                        'ACCEPTED_BY_MERCHANT' => 'ACCEPTED_BY_MERCHANT',
                        'ASSEMBLE' => 'ASSEMBLE',
                        'COMPLETED' => 'COMPLETED',
                        'CANCELLED' => 'CANCELLED',
                        'CANCELLING' => 'CANCELLING',
                        'KASPI_DELIVERY_RETURN_REQUESTED' => 'KASPI_DELIVERY_RETURN_REQUESTED',
                        'RETURNED' => 'RETURNED',
                    ]),

                Tables\Filters\TernaryFilter::make('baseline_ignored')
                    ->label('Baseline'),

                Tables\Filters\Filter::make('has_unmatched_sku')
                    ->label('Ошибки SKU')
                    ->query(fn ($query) => $query->where('internal_stock_status', KaspiOrderInternalStatus::ErrorUnmatchedSku->value)),

                Tables\Filters\Filter::make('handoff_candidate')
                    ->label('Кандидат на передачу')
                    ->query(fn ($query) => $query->whereNotNull('courier_transmission_date')->whereNull('handoff_at')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Kaspi заказ')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('kaspi_order_id')->label('Kaspi ID'),
                            TextEntry::make('kaspi_code')->label('Код заказа'),
                            TextEntry::make('kaspi_status')->label('Status')->badge(),
                            TextEntry::make('kaspi_state')->label('State')->badge(),
                            TextEntry::make('delivery_type')->label('Доставка'),
                            TextEntry::make('internal_stock_status')
                                ->label('Внутренний статус')
                                ->badge()
                                ->formatStateUsing(fn ($state) => $state instanceof KaspiOrderInternalStatus ? $state->label() : (string) $state),
                        ]),
                    ]),

                Section::make('Даты')
                    ->schema([
                        Grid::make(3)->schema([
                            TextEntry::make('kaspi_created_at')->label('Создан')->dateTime('d.m.Y H:i'),
                            TextEntry::make('courier_transmission_planning_date')->label('Планируемая передача')->dateTime('d.m.Y H:i')->placeholder('—'),
                            TextEntry::make('courier_transmission_date')->label('Фактическая передача')->dateTime('d.m.Y H:i')->placeholder('—'),
                            TextEntry::make('handoff_at')->label('Handoff подтверждён')->dateTime('d.m.Y H:i')->placeholder('—'),
                            TextEntry::make('cancelled_at')->label('Отменён')->dateTime('d.m.Y H:i')->placeholder('—'),
                            TextEntry::make('completed_at')->label('Завершён')->dateTime('d.m.Y H:i')->placeholder('—'),
                        ]),
                    ]),

                Section::make('Накладная')
                    ->schema([
                        TextEntry::make('waybill_number')->label('Номер накладной')->placeholder('—'),
                        TextEntry::make('waybill')
                            ->label('Ссылка')
                            ->placeholder('—')
                            ->url(fn (KaspiOrder $record) => $record->waybill)
                            ->openUrlInNewTab(),
                    ]),

                Section::make('Состояние')
                    ->schema([
                        TextEntry::make('baseline_ignored')
                            ->label('Baseline ignored')
                            ->formatStateUsing(fn (bool $state) => $state ? 'Да' : 'Нет'),
                        TextEntry::make('last_synced_at')->label('Последний sync')->dateTime('d.m.Y H:i'),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListKaspiOrders::route('/'),
            'view' => Pages\ViewKaspiOrder::route('/{record}'),
        ];
    }
}

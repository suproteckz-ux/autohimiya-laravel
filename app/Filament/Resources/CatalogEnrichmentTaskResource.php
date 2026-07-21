<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CatalogEnrichmentTaskResource\Pages;
use App\Models\CatalogEnrichmentTask;
use App\Services\Catalog\EnrichmentPublisher;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CatalogEnrichmentTaskResource extends Resource
{
    protected static ?string $model = CatalogEnrichmentTask::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';
    protected static string|UnitEnum|null $navigationGroup = 'Каталог';
    protected static ?string $navigationLabel = 'Техническая очередь';
    protected static ?string $modelLabel = 'задача очереди';
    protected static ?string $pluralModelLabel = 'техническая очередь задач';
    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')->relationship('product', 'name')->searchable()->disabled(),
            Select::make('task_type')->options(array_combine(CatalogEnrichmentTask::TASK_TYPES, CatalogEnrichmentTask::TASK_TYPES))->disabled(),
            Select::make('status')->options(array_combine(CatalogEnrichmentTask::STATUSES, CatalogEnrichmentTask::STATUSES))->required(),
            Select::make('source')->options(array_combine(CatalogEnrichmentTask::SOURCES, CatalogEnrichmentTask::SOURCES))->disabled(),
            TextInput::make('priority')->numeric()->disabled(),
            TextInput::make('confidence')->numeric()->disabled(),
            Textarea::make('reason')->disabled()->columnSpanFull(),
            Textarea::make('error_message')->disabled()->columnSpanFull(),
            Textarea::make('current_value')->disabled()->columnSpanFull(),
            KeyValue::make('current_payload')->disabled()->columnSpanFull(),
            Textarea::make('suggested_value')->disabled()->columnSpanFull(),
            KeyValue::make('suggested_payload')->disabled()->columnSpanFull(),
            KeyValue::make('payload_json')->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product.primaryImage'])->latest('updated_at'))
            ->columns([
                ViewColumn::make('product_task_cell')->label('Товар')->view('filament.enrichment-tasks.task-product-cell')->searchable(['product.name', 'product.sku', 'product.paloma_sku'])->width('330px'),
                TextColumn::make('task_type')->label('Тип')->badge()->sortable()->width('105px'),
                TextColumn::make('status')->label('Статус')->badge()->sortable()->width('105px'),
                TextColumn::make('source')->label('Источник')->badge()->sortable()->width('105px'),
                TextColumn::make('confidence')->label('Confidence')->sortable()->width('90px'),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d.m.Y H:i')->sortable()->width('130px'),
            ])
            ->filters([
                SelectFilter::make('task_type')->label('Тип')->options(array_combine(CatalogEnrichmentTask::TASK_TYPES, CatalogEnrichmentTask::TASK_TYPES)),
                SelectFilter::make('status')->label('Статус')->options(array_combine(CatalogEnrichmentTask::STATUSES, CatalogEnrichmentTask::STATUSES)),
                SelectFilter::make('source')->label('Источник')->options(array_combine(CatalogEnrichmentTask::SOURCES, CatalogEnrichmentTask::SOURCES)),
            ])
            ->actions([
                Action::make('open_product')
                    ->label('Товар')
                    ->icon('heroicon-o-cube')
                    ->iconButton()
                    ->url(fn (CatalogEnrichmentTask $record): string => ProductResource::getUrl('edit', ['record' => $record->product_id])),
                Action::make('view_suggested_value')
                    ->label('Предложение')
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->modalHeading('Suggested value')
                    ->modalContent(fn (CatalogEnrichmentTask $record) => view('filament.enrichment-task-suggested-value', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->iconButton()
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (CatalogEnrichmentTask $record): bool => $record->status === 'draft')
                    ->action(fn (CatalogEnrichmentTask $record): bool => $record->update([
                        'status' => 'approved',
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ])),
                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->iconButton()
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (CatalogEnrichmentTask $record): bool => in_array($record->status, ['draft', 'approved', 'failed'], true))
                    ->action(fn (CatalogEnrichmentTask $record): bool => $record->update([
                        'status' => 'rejected',
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                    ])),
                Action::make('publish')
                    ->label('Publish approved')
                    ->icon('heroicon-o-rocket-launch')
                    ->iconButton()
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (CatalogEnrichmentTask $record): bool => $record->status === 'approved')
                    ->action(fn (CatalogEnrichmentTask $record): bool => app(EnrichmentPublisher::class)->publish($record)),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatalogEnrichmentTasks::route('/'),
            'edit' => Pages\EditCatalogEnrichmentTask::route('/{record}/edit'),
        ];
    }
}

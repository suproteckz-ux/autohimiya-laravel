<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use App\Services\Catalog\ProductThumbnailGenerator;
use App\Support\AdminBrandOptions;
use App\Support\AdminCategoryOptions;
use App\Support\ProductStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-cube';
    protected static string | UnitEnum | null $navigationGroup = 'Каталог';
    protected static ?string $navigationLabel = 'Товары';
    protected static ?string $modelLabel = 'товар';
    protected static ?string $pluralModelLabel = 'товары';
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Редактор товара')
                ->tabs([
                    Tab::make('Основное')
                        ->schema([
                            TextInput::make('name')->label('Название')->required()->maxLength(255)->columnSpanFull(),
                            TextInput::make('slug')
                                ->label('SEO URL')
                                ->required()
                                ->maxLength(255)
                                ->helperText(fn (?Product $record, ?string $state): string => trim(url('/product/'.($state ?: $record?->slug ?: '')).PHP_EOL.'Изменение URL меняет адрес товара. Для старых ссылок нужен редирект.'))
                                ->suffixAction(
                                    Action::make('generate_slug_from_title')
                                        ->label('Сгенерировать из названия')
                                        ->icon('heroicon-o-sparkles')
                                        ->action(fn (Set $set, Get $get) => $set('slug', self::slugFromTitle((string) $get('name'))))
                                ),
                            TextInput::make('sku')->label('SKU')->maxLength(255),
                            Select::make('category_id')->label('Категория')->options(fn (): array => AdminCategoryOptions::active())->searchable()->preload(),
                            Select::make('brand_id')->label('Бренд')->options(fn (): array => AdminBrandOptions::active())->searchable()->preload(),
                            Select::make('product_status')
                                ->label('Статус')
                                ->options(ProductStatus::options())
                                ->helperText('Active synced: товар из Paloma. Active manual: вручную активен. Needs review: требует проверки. Inactive/Archived: не показывать на витрине.')
                                ->required(),
                            Toggle::make('is_featured')->label('Хит продаж'),
                        ])->columns(2),

                    Tab::make('Фото')
                        ->schema([
                            View::make('filament.products.image-preview')->columnSpanFull(),
                            Repeater::make('images')
                                ->label('Изображения')
                                ->relationship('images')
                                ->schema([
                                    FileUpload::make('path')
                                        ->label('Фото')
                                        ->disk('public')
                                        ->directory(fn (?Product $record): string => 'products/'.($record?->id ?: 'new'))
                                        ->image()
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                        ->maxSize(5120)
                                        ->imagePreviewHeight('180')
                                        ->panelAspectRatio('1:1')
                                        ->panelLayout('integrated')
                                        ->downloadable()
                                        ->openable()
                                        ->required(),
                                    TextInput::make('alt')->label('Alt text')->maxLength(255),
                                    TextInput::make('title')->label('Title')->maxLength(255),
                                    Toggle::make('is_primary')->label('Главное фото'),
                                    Hidden::make('source')->default('manual'),
                                    Hidden::make('role')->default('gallery'),
                                    Hidden::make('sort_order')->default(0),
                                    Hidden::make('card_thumb_path'),
                                    Hidden::make('opencart_image_id'),
                                    Hidden::make('original_path'),
                                    Hidden::make('original_name'),
                                ])
                                ->columns(2)
                                ->itemLabel(fn (array $state): ?string => ($state['is_primary'] ?? false) ? 'Главное фото' : ($state['title'] ?? $state['alt'] ?? 'Фото'))
                                ->orderColumn('sort_order')
                                ->reorderable()
                                ->reorderableWithDragAndDrop()
                                ->addable()
                                ->deletable()
                                ->defaultItems(0)
                                ->columnSpanFull(),
                        ]),

                    Tab::make('Контент')
                        ->schema([
                            Textarea::make('short_description')->label('Краткое описание')->rows(3)->columnSpanFull(),
                            RichEditor::make('description')->label('Описание')->columnSpanFull(),
                        ]),

                    Tab::make('Характеристики')
                        ->schema([
                            Repeater::make('attributes')
                                ->label('Характеристики товара')
                                ->relationship('attributes')
                                ->schema([
                                    TextInput::make('group_name')->label('Группа')->maxLength(255)->default('Основные'),
                                    TextInput::make('name')->label('Название')->required()->maxLength(255),
                                    Textarea::make('value')->label('Значение')->required()->rows(2),
                                    TextInput::make('sort_order')->label('Сортировка')->numeric()->default(0),
                                    Toggle::make('is_filterable')->label('Использовать в фильтрах'),
                                ])
                                ->columns(2)
                                ->itemLabel(fn (array $state): ?string => trim(($state['name'] ?? '').' '.($state['value'] ?? '')) ?: 'Характеристика')
                                ->orderColumn('sort_order')
                                ->reorderable()
                                ->reorderableWithDragAndDrop()
                                ->addable()
                                ->deletable()
                                ->defaultItems(0)
                                ->columnSpanFull(),
                        ]),

                    Tab::make('SEO')
                        ->schema([
                            TextInput::make('h1')->label('H1')->maxLength(255),
                            TextInput::make('meta_title')->label('Meta Title')->maxLength(255),
                            Textarea::make('meta_description')->label('Meta Description')->rows(4)->columnSpanFull(),
                        ])->columns(2),

                    Tab::make('Kaspi')
                        ->schema([
                            Section::make('Kaspi карточка')
                                ->description('Kaspi используется только как источник контента. Цены, остатки и заказы не меняются.')
                                ->schema([
                                    TextInput::make('kaspi_product_url')
                                        ->label('Публичная карточка Kaspi')
                                        ->url()
                                        ->maxLength(2048)
                                        ->helperText('Если URL заполнен, менеджер может открыть карточку Kaspi и использовать ее для наполнения.')
                                        ->suffixAction(
                                            Action::make('open_kaspi')
                                                ->label('Открыть')
                                                ->icon('heroicon-o-arrow-top-right-on-square')
                                                ->url(fn (?Product $record): ?string => $record?->kaspi_product_url)
                                                ->visible(fn (?Product $record): bool => filled($record?->kaspi_product_url))
                                                ->openUrlInNewTab()
                                        )
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    Tab::make('Система')
                        ->schema([
                            Section::make('Paloma365')
                                ->description('Цена, остаток и наличие приходят из Paloma и не редактируются вручную.')
                                ->schema([
                                    TextInput::make('paloma_sku')->label('Paloma SKU')->disabled(),
                                    TextInput::make('model')->label('Model')->disabled(),
                                    TextInput::make('price')->label('Цена')->numeric()->disabled(),
                                    TextInput::make('quantity')->label('Остаток')->numeric()->disabled(),
                                    TextInput::make('stock_quantity')->label('Stock quantity')->numeric()->disabled(),
                                    TextInput::make('availability')->label('В наличии')->disabled(),
                                    TextInput::make('last_synced_at')->label('Последняя синхронизация')->disabled(),
                                ])->columns(3)->collapsible(),
                            Section::make('Kaspi diagnostics')
                                ->schema([
                                    TextInput::make('kaspi_source')->label('Источник')->disabled(),
                                    TextInput::make('kaspi_status')->label('Статус Kaspi')->disabled(),
                                    TextInput::make('kaspi_last_sync_at')->label('Последняя проверка')->disabled(),
                                    Textarea::make('kaspi_last_error')->label('Последняя ошибка')->rows(3)->disabled()->columnSpanFull(),
                                ])->columns(3)->collapsible()->collapsed(),
                            Section::make('OpenCart matching')
                                ->schema([
                                    TextInput::make('opencart_product_id')->label('OpenCart Product ID')->disabled(),
                                    TextInput::make('match_method')->label('Match method')->disabled(),
                                    TextInput::make('match_confidence')->label('Match confidence')->disabled(),
                                    TextInput::make('sync_status')->label('Sync status')->disabled(),
                                    Textarea::make('sync_error')->label('Sync error')->disabled()->columnSpanFull(),
                                ])->columns(2)->collapsible()->collapsed(),
                            Section::make('Задачи наполнения')
                                ->schema([
                                    Repeater::make('enrichmentTasks')
                                        ->label('Задачи')
                                        ->relationship('enrichmentTasks')
                                        ->schema([
                                            TextInput::make('task_type')->label('Тип')->disabled(),
                                            TextInput::make('status')->label('Статус')->disabled(),
                                            TextInput::make('source')->label('Источник')->disabled(),
                                            Textarea::make('suggested_value')->label('Предложение')->disabled()->rows(3)->columnSpanFull(),
                                            Textarea::make('reason')->label('Причина')->disabled()->rows(2)->columnSpanFull(),
                                        ])
                                        ->addable(false)
                                        ->deletable(false)
                                        ->reorderable(false)
                                        ->columns(3)
                                        ->columnSpanFull(),
                                ])->collapsible()->collapsed(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['primaryImage', 'brand', 'category'])->withCount(['images', 'enrichmentTasks']))
            ->columns([
                ViewColumn::make('product_admin_cell')
                    ->label('Товар')
                    ->view('filament.products.product-cell')
                    ->searchable(['name', 'sku', 'paloma_sku', 'model'])
                    ->width('320px'),
                SelectColumn::make('category_id')
                    ->label('Категория')
                    ->options(fn (): array => AdminCategoryOptions::active())
                    ->searchable()
                    ->selectablePlaceholder()
                    ->width('145px'),
                SelectColumn::make('brand_id')
                    ->label('Бренд')
                    ->options(fn (): array => AdminBrandOptions::active())
                    ->searchable()
                    ->selectablePlaceholder()
                    ->width('115px'),
                TextColumn::make('price')->label('Цена')->money('KZT')->sortable()->width('90px'),
                TextColumn::make('quantity')
                    ->label('Ост.')
                    ->sortable()
                    ->color(fn (Product $record): string => (int) $record->quantity === 0 ? 'danger' : ((int) $record->quantity <= 5 ? 'warning' : 'success'))
                    ->width('58px'),
                ViewColumn::make('content_status')->label('Контент')->view('filament.products.content-badges')->width('105px'),
                ViewColumn::make('kaspi_status')->label('Kaspi')->view('filament.products.kaspi-status')->width('72px'),
            ])
            ->filters([
                SelectFilter::make('product_status')->label('Статус')->options(ProductStatus::options()),
                SelectFilter::make('category_id')
                    ->label('Категория')
                    ->options(fn (): array => AdminCategoryOptions::active())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('category_id', $data['value']) : $query),
                SelectFilter::make('brand_id')->label('Бренд')->options(fn (): array => AdminBrandOptions::active())->searchable(),
                TernaryFilter::make('availability')->label('В наличии'),
                TernaryFilter::make('has_image')
                    ->label('Фото')
                    ->queries(
                        true: fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereNotNull('primary_image')->orWhereHas('images')),
                        false: fn (Builder $query) => $query->whereNull('primary_image')->whereDoesntHave('images'),
                    ),
                TernaryFilter::make('has_description')
                    ->label('Описание')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('description')->where('description', '<>', ''),
                        false: fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereNull('description')->orWhere('description', '')),
                    ),
                TernaryFilter::make('has_seo')
                    ->label('SEO')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('meta_title')->where('meta_title', '<>', '')->whereNotNull('meta_description')->where('meta_description', '<>', ''),
                        false: fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereNull('meta_title')->orWhere('meta_title', '')->orWhereNull('meta_description')->orWhere('meta_description', '')),
                    ),
                TernaryFilter::make('has_brand')
                    ->label('Бренд')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('brand_id'),
                        false: fn (Builder $query) => $query->whereNull('brand_id'),
                    ),
                TernaryFilter::make('is_featured')->label('Хит'),
                Filter::make('without_brand')->label('Без бренда')->query(fn (Builder $query): Builder => $query->whereNull('brand_id')),
                Filter::make('without_category')->label('Без категории')->query(fn (Builder $query): Builder => $query->whereNull('category_id')),
                Filter::make('featured')->label('Хит продаж')->query(fn (Builder $query): Builder => $query->where('is_featured', true)),
                Filter::make('out_of_stock')->label('Нет в наличии')->query(fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner->where('availability', false)->orWhere('quantity', '<=', 0))),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Редактировать')
                    ->icon('heroicon-o-pencil-square')
                    ->iconButton()
                    ->url(fn (Product $record): string => self::getUrl('edit', ['record' => $record])),
                Action::make('view_storefront')
                    ->label('Открыть товар')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->iconButton()
                    ->url(fn (Product $record): string => route('products.show', $record->slug))
                    ->openUrlInNewTab(),
                Action::make('open_kaspi')
                    ->label('Открыть Kaspi')
                    ->icon('heroicon-o-shopping-bag')
                    ->iconButton()
                    ->visible(fn (Product $record): bool => filled($record->kaspi_product_url))
                    ->url(fn (Product $record): ?string => $record->kaspi_product_url)
                    ->openUrlInNewTab(),
                ActionGroup::make([
                    Action::make('preview')
                        ->label('Preview')
                        ->icon('heroicon-o-eye')
                        ->slideOver()
                        ->modalHeading(fn (Product $record): string => $record->display_name)
                        ->modalContent(fn (Product $record) => view('filament.products.preview', ['record' => $record]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Закрыть'),
                    Action::make('copy_product_url')
                        ->label('Скопировать URL')
                        ->icon('heroicon-o-link')
                        ->modalHeading('URL товара')
                        ->modalContent(fn (Product $record) => view('filament.product-url', ['url' => route('products.show', $record->slug)]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Закрыть'),
                    Action::make('regenerate_thumbnails')
                        ->label('Regenerate thumbnails')
                        ->icon('heroicon-o-photo')
                        ->requiresConfirmation()
                        ->action(function (Product $record): void {
                            $generator = app(ProductThumbnailGenerator::class);
                            $record->images()->get()->each(fn ($image) => $generator->make($image, true));
                        }),
                ])
                    ->label('Еще')
                    ->icon('heroicon-o-ellipsis-vertical')
                    ->iconButton()
                    ->color('gray'),
            ])
            ->bulkActions([
                BulkAction::make('assign_category')
                    ->label('Назначить категорию')
                    ->icon('heroicon-o-tag')
                    ->form([
                        Select::make('category_id')->label('Категория')->options(fn (): array => AdminCategoryOptions::active())->searchable()->required(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        $newCategoryId = (int) $data['category_id'];
                        $defaultCategoryId = \App\Services\Catalog\DefaultCategoryResolver::getOrCreateNewProductsCategoryId();
                        foreach ($records as $record) {
                            $record->update(['category_id' => $newCategoryId]);
                            if ($newCategoryId !== $defaultCategoryId) {
                                $record->categories()->detach($defaultCategoryId);
                            }
                        }
                    }),
                BulkAction::make('assign_brand')
                    ->label('Назначить бренд')
                    ->icon('heroicon-o-building-storefront')
                    ->form([
                        Select::make('brand_id')->label('Бренд')->options(fn (): array => AdminBrandOptions::active())->searchable()->required(),
                    ])
                    ->action(fn (Collection $records, array $data) => $records->each->update(['brand_id' => $data['brand_id']])),
                BulkAction::make('mark_featured')
                    ->label('Добавить в хит продаж')
                    ->icon('heroicon-o-star')
                    ->action(fn (Collection $records) => $records->each->update(['is_featured' => true])),
                BulkAction::make('unmark_featured')
                    ->label('Убрать хит')
                    ->icon('heroicon-o-star')
                    ->color('gray')
                    ->action(fn (Collection $records) => $records->each->update(['is_featured' => false])),
                BulkAction::make('mark_active_manual_bulk')
                    ->label('Пометить active_manual')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $records->each->update(['product_status' => ProductStatus::ACTIVE_MANUAL])),
                BulkAction::make('export_selected_csv')
                    ->label('Export selected CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Collection $records) {
                        $csv = "id,name,paloma_sku,price,quantity,availability,product_status\n";
                        foreach ($records as $product) {
                            $csv .= implode(',', [
                                $product->id,
                                '"'.str_replace('"', '""', $product->name).'"',
                                $product->paloma_sku,
                                $product->price,
                                $product->quantity,
                                $product->availability ? '1' : '0',
                                $product->product_status,
                            ])."\n";
                        }

                        return Response::streamDownload(fn () => print $csv, 'products-selected.csv', ['Content-Type' => 'text/csv']);
                    }),
            ])
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    public static function createTasks(Collection $records, array $types): void
    {
        $records->each(function (Product $product) use ($types): void {
            foreach ($types as $type) {
                CatalogEnrichmentTask::query()->firstOrCreate([
                    'product_id' => $product->id,
                    'task_type' => $type,
                    'status' => 'draft',
                ], [
                    'source' => 'manual',
                    'priority' => 50,
                    'confidence' => 0,
                    'reason' => 'Created from Products bulk action.',
                ]);
            }
        });
    }

    private static function slugFromTitle(string $title): string
    {
        $slug = Str::of($title)->ascii('ru')->slug('-')->lower()->toString();

        return $slug !== '' ? $slug : 'product-'.Str::lower(Str::random(8));
    }
}

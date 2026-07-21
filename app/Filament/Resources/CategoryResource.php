<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryResource\Pages;
use App\Models\Category;
use App\Services\CategoryTreeService;
use App\Support\AdminCategoryOptions;
use App\Support\CategoryTree;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use UnitEnum;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-tag';
    protected static string | UnitEnum | null $navigationGroup = 'Каталог';
    protected static ?string $navigationLabel = 'Категории';
    protected static ?string $modelLabel = 'категория';
    protected static ?string $pluralModelLabel = 'категории';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Редактирование категории')
                ->tabs([
                    Tab::make('Основное')
                        ->schema([
                            Section::make()
                                ->schema([
                                    TextInput::make('name')
                                        ->label('Название')
                                        ->required()
                                        ->maxLength(255)
                                        ->columnSpanFull(),
                                    TextInput::make('slug')
                                        ->label('Slug')
                                        ->required()
                                        ->maxLength(255)
                                        ->helperText('Slug используется в URL категории. Не меняйте его без необходимости.'),
                                    Select::make('parent_id')
                                        ->label('Родительская категория')
                                        ->options(fn (?Category $record): array => AdminCategoryOptions::all($record?->id))
                                        ->searchable()
                                        ->preload()
                                        ->placeholder('— Верхний уровень —')
                                        ->helperText('Нельзя выбрать текущую категорию или ее дочерние категории.'),
                                    Select::make('status')
                                        ->label('Статус')
                                        ->options([
                                            'active' => 'Активна',
                                            'inactive' => 'Скрыта',
                                        ])
                                        ->required(),
                                    TextInput::make('sort_order')
                                        ->label('Порядок сортировки')
                                        ->numeric()
                                        ->default(0),
                                    Toggle::make('show_on_homepage')
                                        ->label('Показывать на главной')
                                        ->helperText('Используется только для витринных блоков.'),
                                    TextInput::make('homepage_sort_order')
                                        ->label('Сортировка на главной')
                                        ->numeric()
                                        ->default(0),
                                    self::countsSummary(),
                                ])
                                ->columns(2),
                        ]),

                    Tab::make('SEO')
                        ->schema([
                            Section::make('SEO-поля')
                                ->schema([
                                    TextInput::make('h1')->label('H1')->maxLength(255),
                                    TextInput::make('meta_title')->label('Meta Title')->maxLength(255),
                                    Textarea::make('meta_description')->label('Meta Description')->rows(4)->columnSpanFull(),
                                    TextInput::make('canonical_url')->label('Canonical URL')->url()->maxLength(255)->columnSpanFull(),
                                    self::seoChecklist(),
                                ])
                                ->columns(2),
                        ]),

                    Tab::make('Изображение')
                        ->schema([
                            Section::make('Изображения категории')
                                ->schema([
                                    FileUpload::make('image_path')
                                        ->label('Изображение категории')
                                        ->disk('public')
                                        ->directory('categories')
                                        ->visibility('public')
                                        ->image()
                                        ->imagePreviewHeight('160')
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                        ->maxSize(4096)
                                        ->helperText('Используется на странице категории и в карточках каталога.')
                                        ->columnSpanFull(),
                                    FileUpload::make('icon_path')
                                        ->label('Иконка категории')
                                        ->disk('public')
                                        ->directory('category-icons')
                                        ->visibility('public')
                                        ->image()
                                        ->imagePreviewHeight('96')
                                        ->acceptedFileTypes(['image/svg+xml', 'image/png', 'image/webp'])
                                        ->maxSize(1024)
                                        ->helperText('Используется для компактных карточек и визуальных блоков.')
                                        ->columnSpanFull(),
                                    self::imagePreview(),
                                ]),
                        ]),

                    Tab::make('Описание')
                        ->schema([
                            Section::make('Краткое описание')
                                ->schema([
                                    RichEditor::make('short_description')
                                        ->label('Краткое описание')
                                        ->toolbarButtons(['bold', 'italic', 'link', 'redo', 'undo'])
                                        ->helperText('Показывается сверху страницы перед подкатегориями и товарами. Рекомендуемый объём: 80–120 слов.')
                                        ->columnSpanFull(),
                                ]),
                            Section::make('SEO-описание')
                                ->schema([
                                    RichEditor::make('seo_description')
                                        ->label('SEO-описание')
                                        ->toolbarButtons(['h2', 'h3', 'bold', 'italic', 'link', 'bulletList', 'orderedList', 'redo', 'undo'])
                                        ->helperText('Показывается внизу страницы после товаров. Используйте H2, H3, списки, FAQ и ссылки.')
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    Tab::make('Дополнительно')
                        ->schema([
                            Section::make('Системная информация')
                                ->schema([
                                    TextInput::make('id')->label('ID')->disabled()->dehydrated(false),
                                    TextInput::make('opencart_category_id')->label('OpenCart ID')->disabled()->dehydrated(false),
                                    TextInput::make('created_at')->label('Создана')->disabled()->dehydrated(false),
                                    TextInput::make('updated_at')->label('Обновлена')->disabled()->dehydrated(false),
                                    self::countsSummary(),
                                ])
                                ->columns(2),
                            Section::make('Устаревшие поля / Legacy')
                                ->description('Эти поля используются только для обратной совместимости. Не редактируйте их вручную.')
                                ->schema([
                                    TextInput::make('image')
                                        ->label('Старый путь к изображению (legacy)')
                                        ->maxLength(255)
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->helperText('Заменён полем «Изображение категории» на вкладке Изображение.'),
                                    TextInput::make('icon')
                                        ->label('Старая иконка (legacy)')
                                        ->maxLength(255)
                                        ->disabled()
                                        ->dehydrated(false),
                                    RichEditor::make('description')
                                        ->label('Старое описание (legacy)')
                                        ->helperText('Больше не используется как основное поле. Заменено на «Краткое описание».')
                                        ->columnSpanFull(),
                                ])
                                ->columns(2)
                                ->collapsible()
                                ->collapsed(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $orderedIds = CategoryTree::orderedIds();

                return $query
                    ->with(['parent'])
                    ->withCount(['children as children_count'])
                    ->when($orderedIds !== [], fn (Builder $query): Builder => $query
                        ->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $orderedIds)).')'));
            })
            ->columns([
                ViewColumn::make('category_tree')
                    ->label('Название')
                    ->view('filament.categories.category-tree-cell')
                    ->state(fn (Category $record): bool => CategoryTree::hasChildren((int) $record->id))
                    ->searchable(['name', 'slug'])
                    ->url(null)
                    ->width('360px'),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->formatStateUsing(fn (?string $state): string => $state ? '/category/'.$state : '—')
                    ->copyable()
                    ->searchable()
                    ->limit(42),
                TextColumn::make('products_count')
                    ->label('Товаров')
                    ->state(fn (Category $record): int => app(CategoryTreeService::class)->getProductCountsMap()[$record->id] ?? 0)
                    ->alignCenter(),
                TextColumn::make('children_count')
                    ->label('Подкатегорий')
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'active' => 'Активна',
                        'inactive' => 'Скрыта',
                        default => $state ?: '—',
                    })
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'active' ? 'success' : 'gray'),
                TextColumn::make('seo_state')
                    ->label('SEO')
                    ->state(fn (Category $record): string => self::hasSeo($record) ? 'OK' : 'Нет')
                    ->badge()
                    ->color(fn (Category $record): string => self::hasSeo($record) ? 'success' : 'warning'),
                ImageColumn::make('category_thumb')
                    ->label('Изобр.')
                    ->square()
                    ->size(40)
                    ->state(fn (Category $record): string => $record->storefront_image_path
                        ? asset('storage/'.$record->storefront_image_path)
                        : ''
                    )
                    ->defaultImageUrl(asset('images/category-placeholder.svg')),
                TextColumn::make('sort_order')
                    ->label('Сорт.')
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'active' => 'Активна',
                        'inactive' => 'Скрыта',
                    ]),
                SelectFilter::make('parent_id')
                    ->label('Родитель')
                    ->options(fn (): array => AdminCategoryOptions::all())
                    ->searchable(),
            ])
            ->actions([
                Action::make('edit')
                    ->label('Редактировать')
                    ->icon('heroicon-o-pencil-square')
                    ->iconButton()
                    ->slideOver()
                    ->modalHeading('Редактирование категории')
                    ->modalSubmitActionLabel('Сохранить изменения')
                    ->modalCancelActionLabel('Отмена')
                    ->modalWidth('2xl')
                    ->fillForm(fn (Category $record): array => $record->attributesToArray())
                    ->schema(fn (Schema $schema): Schema => self::form($schema))
                    ->action(fn (Category $record, array $data): bool => $record->update($data)),
                Action::make('view_storefront')
                    ->label('Открыть категорию')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->iconButton()
                    ->url(fn (Category $record): string => route('categories.show', $record->slug))
                    ->openUrlInNewTab(),
                Action::make('delete')
                    ->label('Удалить')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->modalHeading('Удалить категорию?')
                    ->modalDescription('Это действие нельзя отменить.')
                    ->modalSubmitActionLabel('Удалить')
                    ->modalCancelActionLabel('Отмена')
                    ->action(function (Category $record): void {
                        if ($record->products()->exists() || $record->children()->exists()) {
                            Notification::make()
                                ->title('Категорию нельзя удалить: есть товары или подкатегории.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->delete();

                        Notification::make()
                            ->title('Категория удалена.')
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkAction::make('activate')
                    ->label('Активировать выбранные')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $records->each->update(['status' => 'active']);
                    }),
                BulkAction::make('hide')
                    ->label('Скрыть выбранные')
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $records->each->update(['status' => 'inactive']);
                    }),
                BulkAction::make('delete')
                    ->label('Удалить выбранные')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Удалить выбранные категории?')
                    ->modalDescription('Это действие нельзя отменить.')
                    ->modalSubmitActionLabel('Удалить')
                    ->modalCancelActionLabel('Отмена')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $deleted = 0;
                        $skipped = 0;

                        foreach ($records as $record) {
                            if ($record->products()->exists() || $record->children()->exists()) {
                                $skipped++;
                                continue;
                            }

                            $record->delete();
                            $deleted++;
                        }

                        Notification::make()
                            ->title("Удалено: {$deleted}. Пропущено: {$skipped}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->recordClasses(function (Category $record): string {
                $classes = [
                    'category-tree-row',
                    'category-id-'.$record->id,
                    'category-depth-'.CategoryTree::depthFor((int) $record->id),
                ];

                foreach (CategoryTree::ancestorsFor((int) $record->id) as $ancestorId) {
                    $classes[] = 'category-descendant-of-'.$ancestorId;
                }

                if ($record->status !== 'active') {
                    $classes[] = 'opacity-70';
                }

                return implode(' ', $classes);
            })
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCategories::route('/'),
            'create' => Pages\CreateCategory::route('/create'),
            'edit' => Pages\EditCategory::route('/{record}/edit'),
        ];
    }

    public static function hasSeo(Category $record): bool
    {
        return filled($record->slug)
            && filled($record->meta_title)
            && filled($record->meta_description);
    }

    private static function countsSummary(): Html
    {
        return Html::make(function (?Category $record): HtmlString {
            if (! $record?->exists) {
                return new HtmlString('<div class="text-sm text-gray-500">Счетчики появятся после сохранения категории.</div>');
            }

            $products = app(CategoryTreeService::class)->getProductCountsMap()[$record->id] ?? 0;
            $children = $record->children()->count();

            return new HtmlString(
                '<div class="grid gap-3 sm:grid-cols-2">'.
                '<div class="rounded-xl border border-gray-200 bg-gray-50 p-3"><div class="text-xs text-gray-500">Товаров</div><div class="text-xl font-semibold text-gray-950">'.$products.'</div></div>'.
                '<div class="rounded-xl border border-gray-200 bg-gray-50 p-3"><div class="text-xs text-gray-500">Подкатегорий</div><div class="text-xl font-semibold text-gray-950">'.$children.'</div></div>'.
                '</div>'
            );
        })->columnSpanFull();
    }

    private static function seoChecklist(): Html
    {
        return Html::make(function (?Category $record): HtmlString {
            $checks = [
                'Slug' => filled($record?->slug),
                'Meta Title' => filled($record?->meta_title),
                'Meta Description' => filled($record?->meta_description),
                'H1' => filled($record?->h1),
            ];

            $items = collect($checks)->map(function (bool $ok, string $label): string {
                $color = $ok ? 'text-green-700 bg-green-50 border-green-200' : 'text-amber-700 bg-amber-50 border-amber-200';
                $icon = $ok ? '✓' : '!';

                return '<span class="inline-flex items-center gap-1 rounded-full border px-2 py-1 text-xs font-medium '.$color.'">'.$icon.' '.e($label).'</span>';
            })->implode('');

            return new HtmlString('<div class="flex flex-wrap gap-2">'.$items.'</div>');
        })->columnSpanFull();
    }

    private static function imagePreview(): Html
    {
        return Html::make(function (?Category $record): HtmlString {
            // Show legacy image preview if image_path is empty but old image field is set
            $legacyPath = filled($record?->image) ? $record->image : null;
            if (! $legacyPath) {
                return new HtmlString('');
            }

            $src = e(self::imageUrl($legacyPath));

            return new HtmlString(
                '<div class="col-span-full">'.
                '<p class="text-xs text-gray-500 mb-1">Старое изображение (legacy path: '.e($legacyPath).')</p>'.
                '<div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-3">'.
                '<img src="'.$src.'" alt="" class="h-32 w-full rounded-lg object-contain">'.
                '</div></div>'
            );
        })->columnSpanFull();
    }

    public static function imageUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/')) {
            return asset($path);
        }

        return asset('storage/'.$path);
    }
}

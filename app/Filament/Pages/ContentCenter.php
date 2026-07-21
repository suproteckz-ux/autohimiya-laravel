<?php

namespace App\Filament\Pages;

use App\Models\CatalogEnrichmentTask;
use App\Models\Product;
use App\Services\Catalog\AiContentSuggestionService;
use App\Services\Catalog\BrandSuggestionService;
use App\Services\Catalog\CategorySuggestionService;
use App\Services\Catalog\EnrichmentPublisher;
use App\Services\Catalog\EnrichmentTaskBuilder;
use App\Services\Catalog\ProductImageSuggestionService;
use App\Support\AdminCategoryOptions;
use App\Support\ContentScore;
use App\Support\ProductStatus;
use App\Support\Utf8Sanitizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

class ContentCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-sparkles';
    protected static string | UnitEnum | null $navigationGroup = 'Каталог';
    protected static ?string $navigationLabel = 'Контент-центр';
    protected static ?int $navigationSort = 15;
    protected static ?string $slug = 'content-center';
    protected string $view = 'filament.pages.content-center';

    public string $activeContentTab = 'all';
    public ?int $selectedProductId = null;
    public array $draftEdits = [];

    public function mount(): void
    {
        $this->selectedProductId = Product::query()->orderBy('updated_at', 'desc')->value('id');
        $this->loadDraftEdits();
    }

    public function setContentTab(string $tab): void
    {
        $this->activeContentTab = $tab;
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('create_problem_tasks')->label('Создать задачи для проблемных товаров')->icon('heroicon-o-clipboard-document-check')->action(fn (): bool => $this->createTasksByType('all')),
                Action::make('find_images')->label('Найти фото')->icon('heroicon-o-photo')->action(fn (): bool => $this->createTasksByType('image')),
                Action::make('generate_descriptions')->label('Сгенерировать описания')->icon('heroicon-o-document-text')->action(fn (): bool => $this->generateByTypeForTab('description')),
                Action::make('generate_seo')->label('Сгенерировать SEO')->icon('heroicon-o-document-magnifying-glass')->action(fn (): bool => $this->generateByTypeForTab('seo')),
                Action::make('detect_brands')->label('Определить бренды')->icon('heroicon-o-building-storefront')->action(fn (): bool => $this->generateByTypeForTab('brand')),
                Action::make('prepare_all')->label('Подготовить всё недостающее')->icon('heroicon-o-bolt')->action(fn (): bool => $this->generateByTypeForTab('all')),
            ])
                ->label('⚡ Заполнить каталог')
                ->icon('heroicon-o-bolt')
                ->button()
                ->color('primary'),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->baseProductQuery())
            ->columns([
                ViewColumn::make('product')->label('Товар')->view('filament.content-center.product-cell')->searchable(['name', 'sku', 'paloma_sku', 'model'])->width('300px'),
                TextColumn::make('category.name')->label('Категория')->placeholder('Нет')->limit(20)->width('120px'),
                TextColumn::make('brand.name')->label('Бренд')->placeholder('Нет')->limit(16)->width('100px'),
                ViewColumn::make('content_status')->label('Контент')->view('filament.content-center.content-status')->width('230px'),
                ViewColumn::make('problems')->label('Проблемы')->view('filament.content-center.problems')->width('170px'),
                ViewColumn::make('content_score')->label('Score')->view('filament.content-center.score')->width('78px'),
                ViewColumn::make('priority')->label('Приоритет')->view('filament.content-center.priority')->width('86px'),
                TextColumn::make('last_task_updated_at')->label('Задачи')->state(fn (Product $record): ?string => $record->enrichmentTasks->sortByDesc('updated_at')->first()?->updated_at?->format('d.m H:i'))->placeholder('-')->width('86px'),
                TextColumn::make('updated_at')->label('Изменено')->dateTime('d.m.Y H:i')->sortable()->width('110px'),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Категория')
                    ->options(fn (): array => AdminCategoryOptions::active())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $query->where('category_id', $data['value']) : $query),
                SelectFilter::make('brand_id')->label('Бренд')->relationship('brand', 'name')->searchable()->preload(),
                SelectFilter::make('product_status')->label('Статус')->options(ProductStatus::options()),
                SelectFilter::make('priority')->label('Приоритет')->options([
                    'high' => 'Высокий',
                    'medium' => 'Средний',
                    'low' => 'Низкий',
                ])->query(fn (Builder $query, array $data): Builder => $this->applyPriorityFilter($query, $data['value'] ?? null)),
            ])
            ->actions([
                Action::make('open_panel')
                    ->label('Открыть в панели')
                    ->icon('heroicon-o-eye')
                    ->iconButton()
                    ->action(fn (Product $record): bool => $this->selectProduct($record->id)),
                Action::make('tasks')
                    ->label('Создать задачи')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->iconButton()
                    ->action(fn (Product $record): bool => $this->createMissingTasks(collect([$record]))),
                Action::make('generate')
                    ->label('Сгенерировать недостающее')
                    ->icon('heroicon-o-bolt')
                    ->iconButton()
                    ->action(fn (Product $record): bool => $this->generateForProduct($record, 'all')),
                Action::make('publish')
                    ->label('Опубликовать approved')
                    ->icon('heroicon-o-rocket-launch')
                    ->iconButton()
                    ->requiresConfirmation()
                    ->action(fn (Product $record): bool => $this->publishApprovedForProduct($record)),
            ])
            ->bulkActions([
                BulkAction::make('create_tasks')->label('Создать задачи')->icon('heroicon-o-clipboard-document-check')->requiresConfirmation()->action(fn (Collection $records): bool => $this->createMissingTasks($records)),
                BulkAction::make('generate_all')->label('Сгенерировать недостающее')->icon('heroicon-o-bolt')->requiresConfirmation()->action(fn (Collection $records): bool => $this->generateForProducts($records, 'all')),
                BulkAction::make('generate_seo')->label('Сгенерировать SEO')->icon('heroicon-o-document-magnifying-glass')->requiresConfirmation()->action(fn (Collection $records): bool => $this->generateForProducts($records, 'seo')),
                BulkAction::make('generate_description')->label('Сгенерировать описание')->icon('heroicon-o-document-text')->requiresConfirmation()->action(fn (Collection $records): bool => $this->generateForProducts($records, 'description')),
                BulkAction::make('find_images')->label('Найти фото')->icon('heroicon-o-photo')->requiresConfirmation()->action(fn (Collection $records): bool => $this->generateForProducts($records, 'image')),
                BulkAction::make('assign_brand_task')->label('Определить бренды')->icon('heroicon-o-building-storefront')->requiresConfirmation()->action(fn (Collection $records): bool => $this->generateForProducts($records, 'brand')),
                BulkAction::make('assign_category_task')->label('Предложить категории')->icon('heroicon-o-tag')->requiresConfirmation()->action(fn (Collection $records): bool => $this->generateForProducts($records, 'category')),
                BulkAction::make('approve_drafts')->label('Одобрить все draft')->icon('heroicon-o-check')->color('success')->requiresConfirmation()->action(fn (Collection $records): bool => $this->approveDraftsForProducts($records)),
                BulkAction::make('publish_approved')->label('Опубликовать approved')->icon('heroicon-o-rocket-launch')->color('gray')->requiresConfirmation()->action(fn (Collection $records): bool => $this->publishApprovedForProducts($records)),
                BulkAction::make('reject_drafts')->label('Отклонить draft')->icon('heroicon-o-x-mark')->color('danger')->requiresConfirmation()->action(fn (Collection $records): bool => $this->rejectDraftsForProducts($records)),
                BulkAction::make('deduplicate_tasks')->label('Удалить дубли задач')->icon('heroicon-o-trash')->requiresConfirmation()->action(fn (Collection $records): bool => $this->deduplicateTasks($records)),
            ])
            ->recordAction('open_panel')
            ->defaultSort('updated_at', 'desc')
            ->paginationPageOptions([10, 25, 50])
            ->defaultPaginationPageOption(10);
    }

    public function selectedProduct(): ?Product
    {
        if (! $this->selectedProductId) {
            return null;
        }

        return Product::query()
            ->with(['brand', 'category', 'primaryImage', 'images', 'enrichmentTasks' => fn ($query) => $query->latest('updated_at')])
            ->withCount('images')
            ->find($this->selectedProductId);
    }

    public function selectProduct(int $productId): bool
    {
        $this->selectedProductId = $productId;
        $this->loadDraftEdits();

        return true;
    }

    public function loadDraftEdits(): void
    {
        $product = $this->selectedProduct();
        $this->draftEdits = [];

        if (! $product) {
            return;
        }

        foreach ($product->enrichmentTasks as $task) {
            $this->draftEdits[$task->id] = [
                'suggested_value' => $task->suggested_value,
                'suggested_payload' => $task->suggested_payload ?: [],
            ];
        }
    }

    public function baseProductQuery(): Builder
    {
        $query = Product::query()
            ->with(['primaryImage', 'category', 'brand', 'enrichmentTasks'])
            ->withCount(['images', 'enrichmentTasks']);

        return $this->applyTab($query, $this->activeContentTab);
    }

    public function stats(): array
    {
        $total = Product::query()->count();
        $withPhoto = Product::query()->where(fn (Builder $query) => $query->whereNotNull('primary_image')->orWhereHas('images'))->count();
        $withDescription = Product::query()->whereNotNull('description')->where('description', '<>', '')->count();
        $withSeo = Product::query()->whereNotNull('meta_title')->where('meta_title', '<>', '')->whereNotNull('meta_description')->where('meta_description', '<>', '')->count();
        $withBrand = Product::query()->whereNotNull('brand_id')->count();
        $withCategory = Product::query()->whereNotNull('category_id')->count();
        $average = $total > 0 ? (int) round((($withPhoto + $withDescription + $withSeo + $withBrand + $withCategory) / ($total * 5)) * 100) : 0;
        $attention = $this->attentionQuery(Product::query())->count();

        return compact('total', 'withPhoto', 'withDescription', 'withSeo', 'withBrand', 'withCategory', 'average', 'attention');
    }

    public function tabs(): array
    {
        return [
            'all' => ['label' => 'Все товары', 'count' => Product::query()->count()],
            'attention' => ['label' => 'Требует внимания', 'count' => $this->attentionQuery(Product::query())->count()],
            'without_photo' => ['label' => 'Без фото', 'count' => Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count()],
            'without_description' => ['label' => 'Без описания', 'count' => Product::query()->where(fn (Builder $query) => $query->whereNull('description')->orWhere('description', ''))->count()],
            'without_seo' => ['label' => 'Без SEO', 'count' => Product::query()->where(fn (Builder $query) => $query->whereNull('meta_title')->orWhere('meta_title', '')->orWhereNull('meta_description')->orWhere('meta_description', ''))->count()],
            'without_brand' => ['label' => 'Без бренда', 'count' => Product::query()->whereNull('brand_id')->count()],
            'without_category' => ['label' => 'Без категории', 'count' => Product::query()->whereNull('category_id')->count()],
            'draft' => ['label' => 'Есть draft', 'count' => Product::query()->whereHas('enrichmentTasks', fn (Builder $query) => $query->where('status', 'draft'))->count()],
            'approved' => ['label' => 'Approved', 'count' => Product::query()->whereHas('enrichmentTasks', fn (Builder $query) => $query->where('status', 'approved'))->count()],
            'ready' => ['label' => 'Готово', 'count' => $this->readyQuery(Product::query())->count()],
            'failed' => ['label' => 'Failed', 'count' => Product::query()->whereHas('enrichmentTasks', fn (Builder $query) => $query->where('status', 'failed'))->count()],
        ];
    }

    public function applyTab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'attention' => $this->attentionQuery($query),
            'without_photo' => $query->whereNull('primary_image')->whereDoesntHave('images'),
            'without_description' => $query->where(fn (Builder $inner) => $inner->whereNull('description')->orWhere('description', '')),
            'without_seo' => $query->where(fn (Builder $inner) => $inner->whereNull('meta_title')->orWhere('meta_title', '')->orWhereNull('meta_description')->orWhere('meta_description', '')),
            'without_brand' => $query->whereNull('brand_id'),
            'without_category' => $query->whereNull('category_id'),
            'draft' => $query->whereHas('enrichmentTasks', fn (Builder $inner) => $inner->where('status', 'draft')),
            'approved' => $query->whereHas('enrichmentTasks', fn (Builder $inner) => $inner->where('status', 'approved')),
            'failed' => $query->whereHas('enrichmentTasks', fn (Builder $inner) => $inner->where('status', 'failed')),
            'ready' => $this->readyQuery($query),
            default => $query,
        };
    }

    public function applyPriorityFilter(Builder $query, ?string $priority): Builder
    {
        return match ($priority) {
            'high' => $query->where(fn (Builder $inner) => $inner
                ->where(fn (Builder $photo) => $photo->whereNull('primary_image')->whereDoesntHave('images'))
                ->orWhereNull('description')->orWhere('description', '')
                ->orWhereNull('meta_title')->orWhere('meta_title', '')
                ->orWhereNull('meta_description')->orWhere('meta_description', '')),
            'medium' => $query
                ->whereNotNull('description')->where('description', '<>', '')
                ->whereNotNull('meta_title')->where('meta_title', '<>', '')
                ->whereNotNull('meta_description')->where('meta_description', '<>', '')
                ->where(fn (Builder $inner) => $inner->whereNull('brand_id')->orWhereNull('category_id')),
            'low' => $this->readyQuery($query),
            default => $query,
        };
    }

    public function createTasksByType(string $type): bool
    {
        $tab = match ($type) {
            'image' => 'without_photo',
            'description' => 'without_description',
            'seo' => 'without_seo',
            'brand' => 'without_brand',
            'category' => 'without_category',
            default => 'attention',
        };

        return $this->createTypedTasks($this->applyTab($this->baseTaskProductQuery(), $tab)->limit(500)->get(), [$type]);
    }

    public function generateByTypeForTab(string $type): bool
    {
        return $this->generateForProducts($this->applyTab($this->baseTaskProductQuery(), $this->activeContentTab)->limit(100)->get(), $type);
    }

    public function createMissingTasks(Collection $records): bool
    {
        $builder = app(EnrichmentTaskBuilder::class);
        $records->each(fn (Product $product): array => $builder->createMissingTasksForProduct($product->loadMissing(['brand', 'category', 'primaryImage'])));

        $this->notify('Задачи созданы или обновлены', 'Карточки товаров не изменялись.');

        return true;
    }

    public function createSelectedTasks(): bool
    {
        $product = $this->selectedProduct();

        return $product ? $this->createMissingTasks(collect([$product])) : false;
    }

    public function createTypedTasks(Collection $records, array $types): bool
    {
        $builder = app(EnrichmentTaskBuilder::class);

        $records->each(function (Product $product) use ($builder, $types): void {
            $product->loadMissing(['brand', 'category', 'primaryImage']);

            foreach (array_unique($types) as $type) {
                match ($type) {
                    'all' => $builder->createMissingTasksForProduct($product),
                    'image' => $builder->createImageTask($product),
                    'description' => $builder->createDescriptionTask($product),
                    'seo' => $builder->createSeoTask($product),
                    'brand' => $builder->createBrandTask($product),
                    'category' => $builder->createCategoryTask($product),
                    default => null,
                };
            }
        });

        $this->notify('Задачи подготовлены', 'Созданы или обновлены draft-задачи.');

        return true;
    }

    public function generateSelected(string $type = 'all'): bool
    {
        $product = $this->selectedProduct();

        return $product ? $this->generateForProduct($product, $type) : false;
    }

    public function generateForProducts(Collection $records, string $type): bool
    {
        $created = 0;
        $failed = 0;

        foreach ($records as $product) {
            try {
                $this->generateForProduct($product, $type, false);
                $created++;
            } catch (Throwable $exception) {
                $failed++;
                $this->markProductGenerationFailed($product, $type, $exception);
            }
        }

        $this->notify('Генерация завершена', "Обработано: {$created}. Ошибок: {$failed}. Product не менялся.");

        return true;
    }

    public function generateForProduct(Product $product, string $type = 'all', bool $notify = true): bool
    {
        $product->loadMissing(['brand', 'category', 'primaryImage']);
        $builder = app(EnrichmentTaskBuilder::class);

        foreach ($this->taskTypesForGeneration($product, $type) as $taskType) {
            $payload = match ($taskType) {
                'image' => app(ProductImageSuggestionService::class)->suggest($product),
                'description' => app(AiContentSuggestionService::class)->suggestDescription($product),
                'seo' => app(AiContentSuggestionService::class)->suggestSeo($product),
                'brand' => app(BrandSuggestionService::class)->suggest($product),
                'category' => app(CategorySuggestionService::class)->suggest($product),
                default => [],
            };

            if (in_array($taskType, ['brand', 'category'], true) && (int) ($payload['confidence'] ?? 0) < 70) {
                continue;
            }

            if ($taskType === 'seo') {
                $builder->createTask($product, 'seo_title', $payload['reason'] ?? 'SEO draft generated.', $payload, (int) ($payload['confidence'] ?? 0));
                $builder->createTask($product, 'seo_description', $payload['reason'] ?? 'SEO draft generated.', $payload, (int) ($payload['confidence'] ?? 0));
            } else {
                $builder->createTask($product, $taskType, $payload['reason'] ?? ucfirst($taskType).' draft generated.', $payload, (int) ($payload['confidence'] ?? 0));
            }
        }

        $this->selectedProductId = $product->id;
        $this->loadDraftEdits();

        if ($notify) {
            $this->notify('Draft подготовлен', 'Product не изменялся.');
        }

        return true;
    }

    public function saveDraft(int $taskId): bool
    {
        $task = CatalogEnrichmentTask::query()->findOrFail($taskId);
        $edit = $this->draftEdits[$taskId] ?? [];
        $payload = $task->suggested_payload ?: [];

        if ($task->task_type === 'description') {
            $payload['description'] = $edit['suggested_value'] ?? $task->suggested_value;
        } elseif (in_array($task->task_type, ['seo_title', 'seo_description', 'seo'], true)) {
            $key = $task->task_type === 'seo_description' ? 'meta_description' : 'seo_title';
            $payload[$key] = $edit['suggested_value'] ?? $task->suggested_value;
        } else {
            $payload['value'] = $edit['suggested_value'] ?? $task->suggested_value;
        }

        $task->update([
            'status' => 'draft',
            'suggested_value' => Utf8Sanitizer::cleanString($edit['suggested_value'] ?? $task->suggested_value),
            'suggested_payload' => Utf8Sanitizer::clean($payload),
            'reason' => 'Manual edit from Content Center.',
        ]);

        $this->notify('Draft сохранен', 'Product не изменялся.');

        return true;
    }

    public function approveTask(int $taskId): bool
    {
        CatalogEnrichmentTask::query()->whereKey($taskId)->update([
            'status' => 'approved',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->notify('Draft одобрен', 'Product не изменялся.');

        return true;
    }

    public function rejectTask(int $taskId): bool
    {
        CatalogEnrichmentTask::query()->whereKey($taskId)->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->notify('Draft отклонен', 'Product не изменялся.');

        return true;
    }

    public function publishTask(int $taskId): bool
    {
        $task = CatalogEnrichmentTask::query()->with('product')->findOrFail($taskId);
        $published = app(EnrichmentPublisher::class)->publish($task);

        $this->notify($published ? 'Опубликовано' : 'Не опубликовано', $published ? 'Product обновлен через approved task.' : 'Публиковать можно только approved-задачи.', $published);

        return $published;
    }

    public function approveAllDraftsForSelected(): bool
    {
        $product = $this->selectedProduct();

        return $product ? $this->approveDraftsForProducts(collect([$product])) : false;
    }

    public function publishApprovedForSelected(): bool
    {
        $product = $this->selectedProduct();

        return $product ? $this->publishApprovedForProduct($product) : false;
    }

    public function approveDraftsForProducts(Collection $records): bool
    {
        CatalogEnrichmentTask::query()
            ->whereIn('product_id', $records->pluck('id'))
            ->where('status', 'draft')
            ->update(['status' => 'approved', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'updated_at' => now()]);

        $this->notify('Draft-задачи одобрены', 'Product не изменялся.');

        return true;
    }

    public function publishApprovedForProducts(Collection $records): bool
    {
        $changed = 0;
        foreach ($records as $product) {
            if ($this->publishApprovedForProduct($product, false)) {
                $changed++;
            }
        }

        $this->notify('Публикация завершена', "Товаров с опубликованными изменениями: {$changed}.");

        return true;
    }

    public function publishApprovedForProduct(Product $product, bool $notify = true): bool
    {
        $published = 0;
        $product->loadMissing('enrichmentTasks');

        foreach ($product->enrichmentTasks->where('status', 'approved') as $task) {
            if (app(EnrichmentPublisher::class)->publish($task)) {
                $published++;
            }
        }

        if ($notify) {
            $this->notify('Публикация approved завершена', "Опубликовано задач: {$published}.", $published > 0);
        }

        return $published > 0;
    }

    public function rejectDraftsForProducts(Collection $records): bool
    {
        CatalogEnrichmentTask::query()
            ->whereIn('product_id', $records->pluck('id'))
            ->where('status', 'draft')
            ->update(['status' => 'rejected', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'updated_at' => now()]);

        $this->notify('Draft-задачи отклонены', 'Product не изменялся.');

        return true;
    }

    public function deduplicateTasks(Collection $records): bool
    {
        $records->each(function (Product $product): void {
            $product->enrichmentTasks()
                ->whereIn('status', EnrichmentTaskBuilder::ACTIVE_STATUSES)
                ->orderByDesc('updated_at')
                ->get()
                ->groupBy(fn ($task): string => $task->task_type)
                ->each(fn ($group) => $group->skip(1)->each->update([
                    'status' => 'rejected',
                    'reason' => 'Closed as duplicate from Content Center.',
                ]));
        });

        $this->notify('Дубли закрыты', 'Лишние активные задачи отклонены.');

        return true;
    }

    private function taskTypesForGeneration(Product $product, string $type): array
    {
        if ($type !== 'all') {
            return [$type];
        }

        $types = [];
        if (! ContentScore::hasPhoto($product)) {
            $types[] = 'image';
        }
        if (! ContentScore::hasDescription($product)) {
            $types[] = 'description';
        }
        if (! ContentScore::hasSeo($product)) {
            $types[] = 'seo';
        }
        if (! ContentScore::hasBrand($product)) {
            $types[] = 'brand';
        }
        if (! ContentScore::hasCategory($product)) {
            $types[] = 'category';
        }

        return $types;
    }

    private function markProductGenerationFailed(Product $product, string $type, Throwable $exception): void
    {
        CatalogEnrichmentTask::query()->updateOrCreate([
            'product_id' => $product->id,
            'task_type' => $type,
            'status' => 'failed',
            'source' => 'rule',
        ], [
            'priority' => 90,
            'confidence' => 0,
            'reason' => 'Generation failed from Content Center.',
            'error_message' => $exception->getMessage(),
        ]);
    }

    private function attentionQuery(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where(fn (Builder $photo) => $photo->whereNull('primary_image')->whereDoesntHave('images'))
            ->orWhereNull('description')->orWhere('description', '')
            ->orWhereNull('meta_title')->orWhere('meta_title', '')
            ->orWhereNull('meta_description')->orWhere('meta_description', '')
            ->orWhereNull('brand_id')
            ->orWhereNull('category_id'));
    }

    private function readyQuery(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $inner) => $inner->whereNotNull('primary_image')->orWhereHas('images'))
            ->whereNotNull('description')->where('description', '<>', '')
            ->whereNotNull('meta_title')->where('meta_title', '<>', '')
            ->whereNotNull('meta_description')->where('meta_description', '<>', '')
            ->whereNotNull('brand_id')
            ->whereNotNull('category_id');
    }

    private function baseTaskProductQuery(): Builder
    {
        return Product::query()
            ->with(['brand', 'category', 'primaryImage'])
            ->withCount('images')
            ->orderBy('id');
    }

    private function notify(string $title, string $body, bool $success = true): void
    {
        $notification = Notification::make()
            ->title($title)
            ->body($body);

        if ($success) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->send();
    }
}

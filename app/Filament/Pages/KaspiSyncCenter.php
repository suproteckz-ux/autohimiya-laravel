<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\KaspiContentStatsOverview;
use App\Models\KaspiEnrichmentTask;
use App\Models\Product;
use App\Services\Automation\ArtisanProcessRunner;
use App\Services\Kaspi\KaspiDraftPublisher;
use App\Services\Kaspi\KaspiEnrichmentParser;
use App\Services\Kaspi\KaspiProductDiscoveryService;
use App\Support\ContentScore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnitEnum;

class KaspiSyncCenter extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-document-magnifying-glass';
    protected static string | UnitEnum | null $navigationGroup = 'Контент';
    protected static ?string $navigationLabel = 'Kaspi Content Center';
    protected static ?int $navigationSort = 25;
    protected static ?string $slug = 'kaspi-content-center';
    protected string $view = 'filament.pages.kaspi-sync-center';

    public function getTitle(): string
    {
        return 'Kaspi Content Center';
    }

    public function getBreadcrumb(): string
    {
        return 'Kaspi Content Center';
    }

    protected function getHeaderWidgets(): array
    {
        return [
            KaspiContentStatsOverview::class,
        ];
    }

    public function contentStats(): array
    {
        $buttonQuery = fn (Builder $query) => $query->whereNotNull('sku')->where('sku', '<>', '');
        $urlQuery = fn (Builder $query) => $query
            ->where(fn (Builder $inner) => $inner->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', ''))
            ->orWhereHas('kaspiEnrichmentTasks', fn (Builder $task) => $task->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', ''));

        return [
            'products' => Product::query()->count(),
            'with_button' => $this->globalKaspiButtonReady() ? Product::query()->where($buttonQuery)->count() : 0,
            'url_found' => Product::query()->where($urlQuery)->count(),
            'kaspi_photos_on_site' => Product::query()->whereHas('images', fn (Builder $q) => $q->where('source', 'kaspi'))->count(),
            'kaspi_imported' => Product::query()->whereHas('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'kaspi_imported'))->count(),
            'kaspi_partial' => Product::query()->whereHas('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'kaspi_partial'))->count(),
            'kaspi_no_data' => Product::query()->whereHas('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'kaspi_no_data'))->count(),
            'needs_manual_url' => KaspiEnrichmentTask::query()->where('status', 'needs_manual_url')->count(),
            'photo_found' => KaspiEnrichmentTask::query()->whereNotNull('parsed_images')->where('parsed_images', '<>', '[]')->count(),
            'description_found' => KaspiEnrichmentTask::query()->whereNotNull('parsed_description')->where('parsed_description', '<>', '')->count(),
            'attributes_found' => KaspiEnrichmentTask::query()->whereNotNull('parsed_attributes')->where('parsed_attributes', '<>', '[]')->count(),
            'no_photo' => Product::query()->whereNull('primary_image')->whereDoesntHave('images')->count(),
            'no_description' => Product::query()->where(fn (Builder $query) => $query->whereNull('description')->orWhere('description', '')->orWhere('description', 'Описание готовится'))->count(),
            'broken_text' => Product::query()->where(fn (Builder $query) => $query
                ->where('name', 'like', '%�%')
                ->orWhere('description', 'like', '%�%')
                ->orWhere('short_description', 'like', '%�%')
                ->orWhereHas('attributes', fn (Builder $attribute) => $attribute->where('name', 'like', '%�%')->orWhere('value', 'like', '%�%')))->count(),
            'draft_pending' => KaspiEnrichmentTask::query()->whereIn('status', ['pending', 'draft'])->count(),
            'errors' => KaspiEnrichmentTask::query()->whereIn('status', ['failed', 'error', 'kaspi_blocked'])->count(),
        ];
    }

    public function diagnostics(): array
    {
        return [
            'Merchant Code' => config('services.kaspi.merchant_code') ?: 'not configured',
            'City Code' => config('services.kaspi.city_code') ?: 'not configured',
            'Parser' => config('services.kaspi.enrichment_enabled') ? 'enabled' : 'dry-safe',
            'Template' => (string) config('services.kaspi.button_template', 'button'),
            'Dry Run' => config('services.kaspi.dry_run') ? 'yes' : 'no',
            'Products with Kaspi button' => $this->contentStats()['with_button'],
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Product::query()
                ->with([
                    'primaryImage',
                    'images',
                    'category',
                    'brand',
                    'kaspiEnrichmentTasks' => fn ($query) => $query->latest('updated_at'),
                ])
                ->withCount(['images', 'attributes', 'kaspiEnrichmentTasks'])
                ->whereNotNull('sku')
                ->where('sku', '<>', ''))
            ->columns([
                ViewColumn::make('photo')->label('Фото')->view('filament.kaspi.product-photo')->width('64px'),
                TextColumn::make('name')
                    ->label('Товар')
                    ->state(fn (Product $record): string => $record->display_name)
                    ->searchable(['name', 'sku', 'paloma_sku', 'model'])
                    ->limit(42)
                    ->tooltip(fn (Product $record): string => $record->display_name)
                    ->width('280px'),
                TextColumn::make('sku')->label('SKU')->placeholder('Нет')->searchable()->copyable()->width('120px'),
                IconColumn::make('has_kaspi_button')
                    ->label('Kaspi кнопка')
                    ->state(fn (Product $record): bool => $record->canShowKaspiCreditButton())
                    ->boolean(),
                ViewColumn::make('kaspi_url_status')->label('Kaspi URL')->view('filament.kaspi.url-status')->width('190px'),
                TextColumn::make('kaspi_found_images')
                    ->label('Kaspi фото')
                    ->state(fn (Product $record): string => 'Kaspi фото: '.$this->kaspiImageCount($record))
                    ->badge()
                    ->color(fn (Product $record): string => $this->kaspiImageCount($record) > 0 ? 'success' : 'gray'),
                IconColumn::make('kaspi_found_description')
                    ->label('Kaspi TXT')
                    ->state(fn (Product $record): bool => filled($this->latestTask($record)?->parsed_description))
                    ->boolean(),
                TextColumn::make('kaspi_found_attributes')
                    ->label('Kaspi хар.')
                    ->state(fn (Product $record): string => 'Kaspi хар.: '.$this->kaspiAttributeCount($record))
                    ->badge()
                    ->color(fn (Product $record): string => $this->kaspiAttributeCount($record) > 0 ? 'success' : 'gray'),
                TextColumn::make('site_has_photo')
                    ->label('На сайте фото')
                    ->state(fn (Product $record): string => 'На сайте фото: '.(int) $record->images_count)
                    ->badge()
                    ->color(fn (Product $record): string => (int) $record->images_count > 0 ? 'success' : 'gray'),
                IconColumn::make('site_has_description')->label('На сайте TXT')->state(fn (Product $record): bool => ContentScore::hasDescription($record))->boolean(),
                TextColumn::make('site_has_attributes')
                    ->label('На сайте хар.')
                    ->state(fn (Product $record): string => 'На сайте хар.: '.(int) $record->attributes_count)
                    ->badge()
                    ->color(fn (Product $record): string => (int) $record->attributes_count > 0 ? 'success' : 'gray'),
                TextColumn::make('kaspi_workflow')
                    ->label('Draft')
                    ->state(fn (Product $record): string => $this->workflowState($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Нет URL' => 'gray',
                        'Нужен URL вручную' => 'warning',
                        'URL найден', 'Ожидает контент' => 'info',
                        'Draft pending' => 'primary',
                        'Approved', 'Published', 'Импортирован' => 'success',
                        'Частично', 'Нет данных', 'Заблокирован' => 'warning',
                        'Ошибка' => 'danger',
                        default => 'gray',
                    })
                    ->width('150px'),
                TextColumn::make('kaspi_import_status')
                    ->label('Импорт')
                    ->state(fn (Product $record): string => $this->importStatusLabel($record))
                    ->badge()
                    ->color(fn (Product $record): string => match ($this->latestTask($record)?->status) {
                        'kaspi_imported' => 'success',
                        'kaspi_partial' => 'warning',
                        'kaspi_no_data' => 'gray',
                        'kaspi_blocked' => 'danger',
                        default => 'gray',
                    })
                    ->width('120px'),
                TextColumn::make('site_photo_source')
                    ->label('Источник фото')
                    ->state(fn (Product $record): string => $this->photoSourceLabel($record))
                    ->badge()
                    ->color(fn (Product $record): string => $record->images->where('source', 'kaspi')->isNotEmpty() ? 'success' : ($record->images->isNotEmpty() ? 'info' : 'gray'))
                    ->width('100px'),
                TextColumn::make('kaspi_last_checked_at')
                    ->label('Последняя проверка')
                    ->state(fn (Product $record): ?string => $this->latestTask($record)?->updated_at?->format('d.m.Y H:i'))
                    ->placeholder('Нет')
                    ->width('130px'),
            ])
            ->filters([
                TernaryFilter::make('has_kaspi_button')
                    ->label('Есть Kaspi-кнопка')
                    ->queries(
                        true: fn (Builder $query) => $this->globalKaspiButtonReady() ? $query->whereNotNull('sku')->where('sku', '<>', '') : $query->whereRaw('1 = 0'),
                        false: fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereNull('sku')->orWhere('sku', '')),
                    ),
                Filter::make('without_kaspi_url')
                    ->label('Нет Kaspi URL')
                    ->query(fn (Builder $query): Builder => $query
                        ->where(fn (Builder $inner) => $inner->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', ''))
                        ->whereDoesntHave('kaspiEnrichmentTasks', fn (Builder $task) => $task->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', ''))),
                Filter::make('with_kaspi_url')
                    ->label('URL найден')
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner
                        ->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', '')
                        ->orWhereHas('kaspiEnrichmentTasks', fn (Builder $task) => $task->whereNotNull('kaspi_product_url')->where('kaspi_product_url', '<>', '')))),
                SelectFilter::make('kaspi_task_status')
                    ->label('Статус task')
                    ->options([
                        'kaspi_imported' => '✅ Kaspi импортирован',
                        'kaspi_partial' => '⚠️ Частично импортирован',
                        'kaspi_no_data' => '⬜ Нет данных на Kaspi',
                        'kaspi_blocked' => '🚫 Kaspi заблокировал',
                        'pending' => 'Draft pending',
                        'draft' => 'Draft ready',
                        'approved' => 'Approved',
                        'published' => 'Published',
                        'resolved_from_widget' => 'Resolved from widget',
                        'needs_manual_url' => 'Needs manual URL',
                        'widget_not_found' => 'Widget not found',
                        'widget_timeout' => 'Widget timeout',
                        'kaspi_js_not_loaded' => 'Kaspi JS not loaded',
                        'kaspi_button_not_found' => 'Kaspi button not found',
                        'kaspi_url_not_opened' => 'Kaspi URL not opened',
                        'invalid_kaspi_url' => 'Invalid Kaspi URL',
                        'error' => 'Error',
                        'failed' => 'Ошибки',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('kaspiEnrichmentTasks', fn (Builder $task) => $task->where('status', $data['value']))
                        : $query),
                Filter::make('kaspi_photos_on_site')
                    ->label('Фото Kaspi на сайте')
                    ->query(fn (Builder $query): Builder => $query->whereHas('images', fn (Builder $q) => $q->where('source', 'kaspi'))),
                Filter::make('kaspi_no_data')
                    ->label('Kaspi no data')
                    ->query(fn (Builder $query): Builder => $query->whereHas('kaspiEnrichmentTasks', fn (Builder $q) => $q->where('status', 'kaspi_no_data'))),
                Filter::make('not_imported')
                    ->label('Контент не импортирован')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('kaspi_product_url')
                        ->where('kaspi_product_url', '<>', '')
                        ->whereDoesntHave('kaspiEnrichmentTasks', fn (Builder $q) => $q->whereIn('status', ['kaspi_imported', 'kaspi_partial']))),
                Filter::make('without_photo')->label('Без фото на сайте')->query(fn (Builder $query): Builder => $query->whereNull('primary_image')->whereDoesntHave('images')),
                Filter::make('without_description')->label('Без описания на сайте')->query(fn (Builder $query): Builder => $query->where(fn (Builder $inner) => $inner->whereNull('description')->orWhere('description', ''))),
                Filter::make('without_attributes')->label('Без характеристик на сайте')->query(fn (Builder $query): Builder => $query->whereDoesntHave('attributes')),
            ])
            ->headerActions([
                Action::make('resolve_missing_urls')
                    ->label('CLI: Resolve missing URLs')
                    ->icon('heroicon-o-command-line')
                    ->color('warning')
                    ->action(fn (): bool => $this->showResolverCliCommands()),
                Action::make('resolve_missing_urls_fetch')
                    ->label('CLI: Resolve + Import')
                    ->icon('heroicon-o-command-line')
                    ->color('warning')
                    ->action(fn (): bool => $this->showResolverCliCommands(true)),
                Action::make('mass_import_cli')
                    ->label('Import all Kaspi content (CLI)')
                    ->icon('heroicon-o-arrow-down-on-square-stack')
                    ->color('success')
                    ->action(fn (): bool => $this->showMassImportCliCommand()),
            ])
            ->actions([
                Action::make('check_kaspi')
                    ->label('CLI resolve command')
                    ->tooltip('Показать CLI-команду. Admin не запускает Playwright/browser.')
                    ->icon('heroicon-o-command-line')
                    ->iconButton()
                    ->visible(fn (Product $record): bool => $record->canShowKaspiCreditButton() && ! $this->hasKaspiUrl($record))
                    ->action(fn (Product $record): bool => $this->showResolverCliCommand($record, false)),
                Action::make('cli_command')
                    ->label('CLI command')
                    ->tooltip('Показать команду для запуска в Laragon Console (Kaspi URL resolver)')
                    ->icon('heroicon-o-command-line')
                    ->iconButton()
                    ->visible(fn (Product $record): bool => $record->canShowKaspiCreditButton() && ! $this->hasKaspiUrl($record))
                    ->action(function (Product $record): void {
                        Notification::make()
                            ->title('Запустить в Laragon Console:')
                            ->body(
                                'php artisan kaspi:resolve-widget-urls'
                                .' --product-id='.$record->id
                                .' --delay-ms=3000'
                                .' --only-missing-url=false'
                            )
                            ->info()
                            ->persistent()
                            ->send();
                    }),
                Action::make('resolve_and_fetch')
                    ->label('CLI resolve + import')
                    ->tooltip('Показать CLI-команды. Admin не запускает Playwright/browser.')
                    ->icon('heroicon-o-command-line')
                    ->iconButton()
                    ->visible(fn (Product $record): bool => $record->canShowKaspiCreditButton())
                    ->action(fn (Product $record): bool => $this->showResolverCliCommand($record, true)),
                Action::make('fetch_enrichment')
                    ->label('Получить контент')
                    ->tooltip('Получить фото, описание и характеристики из сохраненного Kaspi URL')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->iconButton()
                    ->visible(fn (Product $record): bool => $this->hasKaspiUrl($record))
                    ->action(fn (Product $record): bool => $this->fetchEnrichment($record)),
                Action::make('import_kaspi_content')
                    ->label('CLI import command')
                    ->tooltip('Показать CLI-команду импорта. Admin не применяет force-import из web-процесса.')
                    ->icon('heroicon-o-command-line')
                    ->iconButton()
                    ->color('success')
                    ->visible(fn (Product $record): bool => $this->hasKaspiUrl($record))
                    ->action(fn (Product $record): bool => $this->showSingleImportCliCommand($record)),
                Action::make('view_draft')
                    ->label('Открыть Draft')
                    ->tooltip('Сравнить текущий контент сайта и найденный контент Kaspi')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->iconButton()
                    ->visible(fn (Product $record): bool => (bool) $this->latestTask($record))
                    ->modalHeading(fn (Product $record): string => 'Kaspi draft: '.$record->display_name)
                    ->modalContent(fn (Product $record) => view('filament.kaspi.draft-preview', ['product' => $record, 'task' => $this->latestTask($record)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрыть'),
                ActionGroup::make([
                    Action::make('try_public_search')
                        ->label('Попробовать найти вручную')
                        ->icon('heroicon-o-magnifying-glass')
                        ->action(fn (Product $record): bool => $this->tryPublicSearch($record)),
                    Action::make('approve_draft')
                        ->label('Approve')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Product $record): bool => in_array($this->latestTask($record)?->status, ['draft', 'pending'], true))
                        ->action(fn (Product $record): bool => $this->setLatestTaskStatus($record, 'approved')),
                    Action::make('reject_draft')
                        ->label('Reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (Product $record): bool => (bool) $this->latestTask($record))
                        ->action(fn (Product $record): bool => $this->setLatestTaskStatus($record, 'rejected')),
                    Action::make('publish_draft')
                        ->label('Publish')
                        ->icon('heroicon-o-rocket-launch')
                        ->requiresConfirmation()
                        ->visible(fn (Product $record): bool => $this->latestTask($record)?->status === 'approved')
                        ->form([
                            Checkbox::make('apply_photo')->label('Применить фото')->default(fn (Product $record): bool => ! ContentScore::hasPhoto($record)),
                            Checkbox::make('apply_description')->label('Применить описание')->default(fn (Product $record): bool => blank($record->description)),
                            Checkbox::make('apply_attributes')->label('Применить характеристики')->default(fn (Product $record): bool => $this->kaspiAttributeCount($record) > 0),
                        ])
                        ->action(fn (Product $record, array $data): bool => $this->publishDraft($record, $data, false)),
                    Action::make('publish_dry_run')
                        ->label('Publish (Dry Run)')
                        ->icon('heroicon-o-clipboard-document-list')
                        ->visible(fn (Product $record): bool => (bool) $this->latestTask($record))
                        ->action(fn (Product $record): bool => $this->publishDraft($record, [], true)),
                    Action::make('republish_cleaned')
                        ->label('Republish cleaned content')
                        ->icon('heroicon-o-arrow-path')
                        ->requiresConfirmation()
                        ->visible(fn (Product $record): bool => (bool) $this->latestTask($record))
                        ->form([
                            Checkbox::make('replace_kaspi_attributes')->label('Удалить старые Kaspi-характеристики и записать cleaned attributes')->default(true),
                            Checkbox::make('apply_photo')->label('Перезаписать фото')->default(false),
                            Checkbox::make('apply_description')->label('Перезаписать описание')->default(false),
                        ])
                        ->action(fn (Product $record, array $data): bool => $this->republishCleanedContent($record, $data)),
                    Action::make('open_storefront')
                        ->label('Открыть товар')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (Product $record): string => route('products.show', $record->slug))
                        ->openUrlInNewTab(),
                    Action::make('open_kaspi')
                        ->label('Открыть Kaspi')
                        ->icon('heroicon-o-shopping-bag')
                        ->visible(fn (Product $record): bool => $this->hasKaspiUrl($record))
                        ->url(fn (Product $record): ?string => $this->kaspiUrl($record))
                        ->openUrlInNewTab(),
                ])
                    ->label('Еще')
                    ->tooltip('Дополнительные действия')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->iconButton(),
            ])
            ->bulkActions([
                BulkAction::make('check_selected')
                    ->label('Resolve selected widget URLs')
                    ->icon('heroicon-o-bolt')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records): bool => $this->resolveWidgetUrls($records, false)),
                BulkAction::make('check_selected_fetch')
                    ->label('Resolve selected widget URLs + Fetch')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records): bool => $this->resolveWidgetUrls($records, true)),
                BulkAction::make('import_selected_kaspi_cli')
                    ->label('Import Kaspi content (CLI command)')
                    ->icon('heroicon-o-arrow-down-on-square')
                    ->color('success')
                    ->action(fn (Collection $records): bool => $this->showImportCliCommand($records)),
                BulkAction::make('create_tasks')
                    ->label('Создать задачи')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->action(fn (Collection $records): bool => $this->createEnrichmentTasks($records)),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    public function globalKaspiButtonReady(): bool
    {
        return filled(config('services.kaspi.merchant_code')) && filled(config('services.kaspi.city_code'));
    }

    public function kaspiUrl(Product $product): ?string
    {
        return $product->kaspi_product_url ?: $this->latestTask($product)?->kaspi_product_url;
    }

    public function hasKaspiUrl(Product $product): bool
    {
        return filled($this->kaspiUrl($product));
    }

    public function kaspiImageCount(Product $product): int
    {
        $task = $this->latestTask($product);

        return count((array) data_get($task?->raw_payload, 'cleaned.images', $task?->parsed_images ?: []));
    }

    public function kaspiAttributeCount(Product $product): int
    {
        $task = $this->latestTask($product);

        return count((array) data_get($task?->raw_payload, 'cleaned.attributes', $task?->parsed_attributes ?: []));
    }

    public function workflowState(Product $product): string
    {
        $task = $this->latestTask($product);

        return match (true) {
            ! $this->hasKaspiUrl($product) && $task?->status === 'needs_manual_url' => 'Нужен URL вручную',
            ! $this->hasKaspiUrl($product) => 'Нет URL',
            ! $task => 'URL найден',
            $task->status === 'kaspi_imported' => 'Импортирован',
            $task->status === 'kaspi_partial' => 'Частично',
            $task->status === 'kaspi_no_data' => 'Нет данных',
            $task->status === 'kaspi_blocked' => 'Заблокирован',
            $task->status === 'needs_manual_url' => 'Нужен URL вручную',
            in_array($task->status, ['failed', 'error'], true) => 'Ошибка',
            $task->status === 'approved' => 'Approved',
            $task->status === 'published' => 'Published',
            filled(data_get($task->raw_payload, 'cleaned.description', $task->parsed_description))
                || $this->kaspiImageCount($product) > 0
                || $this->kaspiAttributeCount($product) > 0 => 'Draft pending',
            default => 'Ожидает контент',
        };
    }

    public function showResolverCliCommands(bool $includeImport = false): bool
    {
        $commands = [
            'php artisan kaspi:resolve-widget-urls --limit=50 --delay-ms=5000 -vvv',
            'php artisan kaspi:resolve-widget-urls --limit=0 --delay-ms=5000 -vvv',
        ];

        if ($includeImport) {
            $commands[] = 'php artisan kaspi:import-content --limit=0 --delay-ms=3000 --force=true';
        }

        Notification::make()
            ->title('Kaspi resolver запускается только из CLI')
            ->body(
                "Kaspi URL resolver uses Playwright browser and must be run from CLI. Admin cannot launch browser reliably.\n\n"
                .implode("\n", $commands)
            )
            ->warning()
            ->persistent()
            ->send();

        return true;
    }

    public function showResolverCliCommand(Product $product, bool $includeImport = false): bool
    {
        $commands = [
            'php artisan kaspi:resolve-widget-urls --product-id='.$product->id.' --delay-ms=5000 --only-missing-url=false -vvv',
        ];

        if ($includeImport) {
            $commands[] = 'php artisan kaspi:import-content --product-id='.$product->id.' --delay-ms=3000 --force=true';
        }

        Notification::make()
            ->title('Запустить в терминале')
            ->body(
                "Kaspi URL resolver uses Playwright browser and must be run from CLI. Admin cannot launch browser reliably.\n\n"
                .implode("\n", $commands)
            )
            ->info()
            ->persistent()
            ->send();

        return true;
    }

    public function resolveWidgetUrl(Product $product, bool $fetchContent = false): bool
    {
        try {
            $result = $this->runResolveWidgetArtisan($product, $fetchContent);
        } catch (Throwable $exception) {
            $result = [
                'status' => 'error',
                'error' => $this->friendlyResolverError($exception->getMessage()),
                'resolved_kaspi_url' => null,
            ];
        }

        $this->resetTable();

        // Subprocess could not connect to MySQL from web context — show the CLI command instead.
        if (($result['status'] ?? null) === 'process_failed') {
            Notification::make()
                ->title('Resolver process failed — use CLI')
                ->body(
                    'php artisan kaspi:resolve-widget-urls'
                    .' --product-id='.$product->id
                    .' --delay-ms=3000'
                    .' --only-missing-url=false'
                )
                ->warning()
                ->persistent()
                ->send();

            return true;
        }

        $ok = ($result['status'] ?? null) === 'resolved_from_widget' && filled($result['resolved_kaspi_url'] ?? null);

        Notification::make()
            ->title($ok ? 'Kaspi URL saved' : $this->resolverStatusLabel((string) ($result['status'] ?? 'error')))
            ->body($ok ? (string) $result['resolved_kaspi_url'] : $this->friendlyResolverError((string) ($result['error'] ?? $result['status'] ?? '')))
            ->status($ok ? 'success' : 'warning')
            ->send();

        return true;
    }

    public function resolveWidgetUrls(Collection $products, bool $fetchContent = false): bool
    {
        $processed = 0;
        $resolved = 0;
        $failed = 0;
        $firstErrors = [];

        foreach ($products->filter(fn (Product $product): bool => $product->canShowKaspiCreditButton()) as $product) {
            try {
                $result = $this->runResolveWidgetArtisan($product, $fetchContent);
                $processed++;

                if (($result['status'] ?? null) === 'resolved_from_widget' && filled($result['resolved_kaspi_url'] ?? null)) {
                    $resolved++;
                } else {
                    $failed++;
                    if (count($firstErrors) < 5) {
                        $firstErrors[] = $product->sku.': '.$this->resolverStatusLabel((string) ($result['status'] ?? 'error'));
                    }
                }
            } catch (Throwable $exception) {
                $processed++;
                $failed++;
                if (count($firstErrors) < 5) {
                    $firstErrors[] = $product->sku.': '.$this->friendlyResolverError($exception->getMessage());
                }
            }
        }

        $this->resetTable();

        Notification::make()
            ->title('Widget resolver finished')
            ->body(collect([
                'Processed: '.$processed,
                'URL saved: '.$resolved,
                'Needs attention: '.$failed,
                $firstErrors === [] ? null : 'First issues: '.implode('; ', $firstErrors),
            ])->filter()->implode("\n"))
            ->status($failed > 0 ? 'warning' : 'success')
            ->send();

        return true;
    }

    public function resolveMissingWidgetUrls(bool $fetchContent = false): bool
    {
        $products = Product::query()
            ->with(['kaspiEnrichmentTasks' => fn ($query) => $query->latest('updated_at')])
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->where(fn (Builder $query) => $query->whereNull('kaspi_product_url')->orWhere('kaspi_product_url', ''))
            ->limit(25)
            ->get()
            ->filter(fn (Product $product): bool => $product->canShowKaspiCreditButton());

        return $this->resolveWidgetUrls($products, $fetchContent);
    }

    private function runResolveWidgetArtisan(Product $product, bool $fetchContent): array
    {
        $arguments = [
            'kaspi:resolve-widget-urls',
            '--product-id='.$product->id,
            '--limit=1',
            '--headless',
            '--delay-ms=3000',
            '--only-missing-url=false',
            '--retry-not-found',
            '--fetch-content='.($fetchContent ? 'true' : 'false'),
        ];

        $result = app(ArtisanProcessRunner::class)->run($arguments, ['APP_URL' => config('app.url')], 180);
        $stdout = (string) ($result['stdout'] ?? '');
        $stderr = (string) ($result['stderr'] ?? '');
        $exitCode = $result['exit_code'] ?? null;
        $durationMs = (int) ($result['duration_ms'] ?? 0);

        // Unset relation before refresh so latestTask() re-queries with latest('updated_at'),
        // bypassing any relation cache that was populated before the subprocess ran.
        $product->unsetRelation('kaspiEnrichmentTasks');
        $product->refresh();

        $task = $this->latestTask($product);

        // Save diagnostics to existing task only — don't create a phantom record on process failure
        if ($task) {
            $diagnostics = [
                'source'      => 'filament_artisan_action',
                'cwd'         => $result['cwd'] ?? base_path(),
                'app_url'     => 'http://127.0.0.1:8000',
                'php_binary'  => $result['php_binary'] ?? PHP_BINARY,
                'command'     => $result['command'] ?? implode(' ', $arguments),
                'exit_code'   => $exitCode,
                'successful'  => (bool) ($result['successful'] ?? false),
                'stdout'      => $stdout,
                'stderr'      => $stderr,
                'duration_ms' => $durationMs,
                'env_keys'    => $result['env_keys'] ?? [],
                'db_connection' => $result['db_connection'] ?? null,
                'db_host' => $result['db_host'] ?? null,
                'db_port' => $result['db_port'] ?? null,
                'db_database' => $result['db_database'] ?? null,
                'cache_store' => $result['cache_store'] ?? null,
            ];
            $rawPayload = (array) ($task->raw_payload ?: []);
            data_set($rawPayload, 'diagnostics.filament_artisan_resolver', $diagnostics);
            $task->forceFill(['raw_payload' => $rawPayload])->save();
        }

        // If the subprocess itself failed, report that clearly — never surface a stale task status
        if (! (bool) ($result['successful'] ?? false)) {
            $detail = mb_substr(strip_tags($stderr ?: $stdout ?: 'no output'), 0, 200);

            return [
                'product_id'         => $product->id,
                'sku'                => $product->sku,
                'product_url'        => route('products.show', $product->slug),
                'widget_found'       => null,
                'button_found'       => null,
                'resolved_kaspi_url' => null,
                'status'             => 'process_failed',
                'error'              => "exit {$exitCode} · {$detail}",
                'duration_ms'        => $durationMs,
            ];
        }

        // Subprocess succeeded: product.kaspi_product_url is the source of truth
        $resolvedUrl = $product->kaspi_product_url;

        if (! filled($resolvedUrl)) {
            // URL not yet in product — check task for actual resolver status
            $task ??= $this->createTaskRecord($product);
            $task->refresh();
            $resolvedUrl = $task->kaspi_product_url;
        }

        $status = filled($resolvedUrl) ? 'resolved_from_widget' : ($task?->status ?: 'needs_manual_url');
        $error = filled($resolvedUrl)
            ? null
            : $this->friendlyResolverError($task?->error ?: $stderr ?: $stdout ?: 'URL not received.');

        return [
            'product_id'         => $product->id,
            'sku'                => $product->sku,
            'product_url'        => route('products.show', $product->slug),
            'widget_found'       => ($task?->status === 'widget_not_found') ? 'no' : null,
            'button_found'       => ($task?->status === 'kaspi_button_not_found') ? 'no' : null,
            'resolved_kaspi_url' => $resolvedUrl,
            'status'             => $status,
            'error'              => $error,
            'duration_ms'        => $durationMs,
        ];
    }

    public function tryPublicSearch(Product $product): bool
    {
        $result = app(KaspiProductDiscoveryService::class)->searchPublic($product, (bool) config('services.kaspi.dry_run', true));

        Notification::make()
            ->title('Public search: '.$result['status'])
            ->body($result['error'] ?: ($result['url'] ?? ''))
            ->success()
            ->send();

        return true;
    }

    public function createEnrichmentTasks(Collection $products): bool
    {
        $products->each(fn (Product $product) => $this->createTaskRecord($product));
        $this->resetTable();

        return true;
    }

    public function fetchEnrichment(Product $product): bool
    {
        $task = $this->latestTask($product) ?: $this->createTaskRecord($product);
        $url = $this->kaspiUrl($product);

        if ((bool) config('services.kaspi.dry_run', true) || ! config('services.kaspi.enrichment_enabled')) {
            Notification::make()
                ->title('Dry-run')
                ->body('Kaspi page was not requested. Disable KASPI_DRY_RUN and enable KASPI_ENRICHMENT_ENABLED for real parsing.')
                ->warning()
                ->send();

            return true;
        }

        if (blank($url)) {
            $task->update(['status' => 'needs_manual_url', 'error' => 'Kaspi URL is required before content fetch.']);
            Notification::make()
                ->title('Kaspi URL нужен вручную')
                ->body('Товар привязан к Kaspi-кнопке, но URL карточки нужно подтвердить или вставить вручную.')
                ->warning()
                ->send();

            return true;
        }

        try {
            $task->update([
                'kaspi_product_url' => $url,
                'status' => 'running',
                'started_at' => now(),
                'error' => null,
            ]);
            sleep(max(1, (int) config('services.kaspi.rate_limit_seconds', 10)));
            $response = Http::timeout(20)
                ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
                ->get($url);
            $payload = app(KaspiEnrichmentParser::class)->parse($response->body(), $url);

            $task->update([
                'status' => 'draft',
                'parsed_title' => ['value' => $payload['name'] ?? null],
                'parsed_images' => $payload['images'] ?? [],
                'parsed_description' => $payload['description'] ?? null,
                'parsed_attributes' => $payload['attributes'] ?? [],
                'parsed_brand' => $payload['brand'] ?? null,
                'parsed_category' => $payload['category'] ?? null,
                'raw_payload' => $payload,
                'finished_at' => now(),
                'error' => null,
            ]);

            $this->resetTable();
            Notification::make()->title('Kaspi draft created')->success()->send();
        } catch (Throwable $exception) {
            $task->update(['status' => 'failed', 'finished_at' => now(), 'error' => $exception->getMessage()]);
            Notification::make()->title('Kaspi fetch failed')->body($exception->getMessage())->danger()->send();
        }

        return true;
    }

    public function setLatestTaskStatus(Product $product, string $status): bool
    {
        $task = $this->latestTask($product);

        if (! $task) {
            Notification::make()->title('Kaspi draft не найден')->warning()->send();

            return true;
        }

        $task->update(['status' => $status]);
        $this->resetTable();
        Notification::make()->title('Kaspi task: '.$status)->success()->send();

        return true;
    }

    public function publishDraft(Product $product, array $data = [], bool $dryRun = false): bool
    {
        $task = $this->latestTask($product);

        if (! $task) {
            Notification::make()->title('Kaspi draft не найден')->warning()->send();

            return true;
        }

        $result = app(KaspiDraftPublisher::class)->publish($task, [
            'dry_run' => $dryRun,
            'apply_photo' => $data['apply_photo'] ?? true,
            'apply_description' => $data['apply_description'] ?? true,
            'apply_attributes' => $data['apply_attributes'] ?? true,
        ]);

        $body = collect([
            'Фото: '.($result['photo']['will_apply'] ? 'будет добавлено' : $result['photo']['reason']),
            'Описание: '.($result['description']['will_apply'] ? 'будет добавлено' : $result['description']['reason']),
            'Характеристики: '.($result['attributes']['will_apply'] ? 'будут добавлены' : $result['attributes']['reason']),
        ])->implode("\n");

        $body = collect([
            'Фото: '.($result['photo']['will_apply']
                ? ($dryRun ? 'будет добавлено '.$result['photo']['count'].' из '.$result['photo']['count'] : 'добавлено '.($result['photo']['added'] ?? 0).' из '.$result['photo']['count'])
                : $result['photo']['reason']),
            'Описание: '.($result['description']['will_apply']
                ? ($dryRun ? 'будет добавлено' : 'добавлено')
                : $result['description']['reason']),
            'Характеристики: '.($result['attributes']['will_apply']
                ? ($dryRun ? 'будет добавлено '.$result['attributes']['count'] : 'добавлено '.($result['attributes']['added'] ?? 0))
                : $result['attributes']['reason']),
            'Отклонено изображений: '.($result['rejected_images'] ?? 0),
            'Пропущено служебных характеристик: '.($result['skipped_service_attributes'] ?? 0),
            'Цена, остаток и SKU не изменялись.',
        ])->implode("\n");

        $product->refresh();
        $this->resetTable();

        Notification::make()
            ->title($dryRun ? 'Publish dry-run' : 'Draft published')
            ->body($body)
            ->success()
            ->send();

        return true;
    }

    private function friendlyResolverError(string $message): string
    {
        if (str_contains($message, 'std::shared_ptr')) {
            return 'Playwright browser did not start. Restart Laragon/Node and try again.';
        }

        if (str_contains($message, 'process_error')) {
            return 'Playwright browser did not return a valid result.';
        }

        return strtok($message, "\r\n") ?: 'Resolver failed.';
    }

    private function resolverStatusLabel(string $status): string
    {
        return match ($status) {
            'process_failed' => 'Resolver process failed',
            'widget_not_found' => 'Widget not found',
            'widget_timeout' => 'Widget did not load in time',
            'kaspi_js_not_loaded' => 'Kaspi JS not loaded',
            'kaspi_button_not_found' => 'Kaspi button not found',
            'kaspi_url_not_opened' => 'URL not received',
            'invalid_kaspi_url' => 'Invalid Kaspi URL',
            'needs_manual_url' => 'URL needs manual confirmation',
            default => 'Kaspi URL not resolved',
        };
    }

    public function republishCleanedContent(Product $product, array $data = []): bool
    {
        $task = $this->latestTask($product);

        if (! $task) {
            Notification::make()->title('Kaspi draft не найден')->warning()->send();

            return true;
        }

        $result = app(KaspiDraftPublisher::class)->publish($task, [
            'dry_run' => false,
            'apply_photo' => (bool) ($data['apply_photo'] ?? false),
            'apply_description' => (bool) ($data['apply_description'] ?? false),
            'apply_attributes' => (bool) ($data['replace_kaspi_attributes'] ?? true),
            'replace_kaspi_attributes' => (bool) ($data['replace_kaspi_attributes'] ?? true),
            'force_attributes' => (bool) ($data['replace_kaspi_attributes'] ?? true),
        ]);

        $this->resetTable();

        Notification::make()
            ->title('Cleaned content republished')
            ->body(collect([
                'Фото: '.(($result['photo']['added'] ?? 0).' / '.$result['photo']['count']),
                'Описание: '.(($result['description']['added'] ?? 0) ? 'добавлено' : $result['description']['reason']),
                'Характеристики: добавлено '.($result['attributes']['added'] ?? 0),
                'Пропущено служебных характеристик: '.($result['skipped_service_attributes'] ?? 0),
                'Цена, остаток и SKU не изменялись.',
            ])->implode("\n"))
            ->success()
            ->send();

        return true;
    }

    public function latestTask(Product $product): ?KaspiEnrichmentTask
    {
        if (! $product->relationLoaded('kaspiEnrichmentTasks')) {
            $product->load(['kaspiEnrichmentTasks' => fn ($query) => $query->latest('updated_at')]);
        }

        return $product->kaspiEnrichmentTasks->sortByDesc('updated_at')->first();
    }

    public function importStatusLabel(Product $product): string
    {
        return match ($this->latestTask($product)?->status) {
            'kaspi_imported' => 'Импортирован',
            'kaspi_partial' => 'Частично',
            'kaspi_no_data' => 'Нет данных',
            'kaspi_blocked' => 'Заблокирован',
            default => '—',
        };
    }

    public function photoSourceLabel(Product $product): string
    {
        if ($product->images->where('source', 'kaspi')->isNotEmpty()) {
            return 'kaspi';
        }

        if ($product->images->isNotEmpty()) {
            return 'site';
        }

        return 'нет';
    }

    private function createTaskRecord(Product $product): KaspiEnrichmentTask
    {
        $url = $product->kaspi_product_url;
        $status = filled($url) ? 'pending' : ($product->canShowKaspiCreditButton() ? 'needs_manual_url' : 'failed');

        return KaspiEnrichmentTask::query()->firstOrCreate([
            'product_id' => $product->id,
            'kaspi_merchant_sku' => $product->sku,
        ], [
            'kaspi_product_url' => $url,
            'missing_photo' => ! ContentScore::hasPhoto($product),
            'missing_description' => blank($product->description),
            'missing_attributes' => $product->attributes()->count() === 0,
            'status' => $status,
            'source' => filled($url) ? 'manual_url' : 'kaspi_widget_needs_manual_url',
            'error' => filled($url) ? null : 'Kaspi button is present, but URL must be confirmed manually.',
        ]);
    }

    public function importKaspiContent(Product $product): bool
    {
        $url = $this->kaspiUrl($product);

        if (blank($url)) {
            Notification::make()->title('Kaspi URL не найден')->warning()->send();

            return true;
        }

        if (! config('services.kaspi.enrichment_enabled')) {
            Notification::make()
                ->title('KASPI_ENRICHMENT_ENABLED не включён')
                ->body('Установите KASPI_ENRICHMENT_ENABLED=true в .env и выполните php artisan optimize:clear.')
                ->warning()
                ->persistent()
                ->send();

            return true;
        }

        try {
            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'AutohimiyaKzBot/1.0 (+https://autohimiki.kz)'])
                ->get($url);

            if (! $response->successful()) {
                Notification::make()
                    ->title('Kaspi заблокировал запро��')
                    ->body('HTTP '.$response->status().' — попробуйте позже.')
                    ->warning()
                    ->send();

                $task = $this->latestTask($product) ?: $this->createTaskRecord($product);
                $task->update(['status' => 'kaspi_blocked', 'error' => 'HTTP '.$response->status(), 'finished_at' => now()]);
                $this->resetTable();

                return true;
            }

            $payload = app(KaspiEnrichmentParser::class)->parse($response->body(), $url);

            $task = $this->latestTask($product);
            $taskFill = [
                'kaspi_product_url' => $url,
                'status' => 'running',
                'started_at' => now(),
                'parsed_title' => ['value' => $payload['name'] ?? null],
                'parsed_images' => $payload['images'] ?? [],
                'parsed_description' => $payload['description'] ?? null,
                'parsed_attributes' => $payload['attributes'] ?? [],
                'parsed_brand' => $payload['brand'] ?? null,
                'parsed_category' => $payload['category'] ?? null,
                'raw_payload' => $payload,
                'finished_at' => now(),
                'error' => null,
            ];

            if ($task) {
                $task->update($taskFill);
                $task->refresh();
            } else {
                $task = KaspiEnrichmentTask::query()->create(array_merge($taskFill, [
                    'product_id' => $product->id,
                    'kaspi_merchant_sku' => $product->sku,
                    'source' => 'filament_import',
                ]));
            }

            $result = app(KaspiDraftPublisher::class)->publish($task, [
                'dry_run' => false,
                'apply_photo' => true,
                'apply_description' => true,
                'apply_attributes' => true,
                'force_photo' => true,
                'force_description' => true,
                'force_attributes' => true,
                'replace_kaspi_attributes' => true,
            ]);

            $photosAdded = (int) ($result['photo']['added'] ?? 0);
            $descAdded = (int) ($result['description']['added'] ?? 0);
            $attrsAdded = (int) ($result['attributes']['added'] ?? 0);

            $images = (array) data_get($payload, 'cleaned.images', []);
            $description = data_get($payload, 'cleaned.description');
            $attributes = (array) data_get($payload, 'cleaned.attributes', []);

            $available = (int) (count($images) > 0) + (int) filled($description) + (int) (count($attributes) > 0);
            $applied = (int) ($photosAdded > 0) + (int) ($descAdded > 0) + (int) ($attrsAdded > 0);

            $importStatus = match (true) {
                $available === 0 => 'kaspi_no_data',
                $applied === $available => 'kaspi_imported',
                $applied > 0 => 'kaspi_partial',
                default => 'kaspi_no_data',
            };

            $task->update(['status' => $importStatus, 'finished_at' => now()]);

            $product->unsetRelation('kaspiEnrichmentTasks');
            $this->resetTable();

            Notification::make()
                ->title($importStatus === 'kaspi_imported' ? 'Kaspi контент импортирован' : 'Kaspi контент частично импортирован')
                ->body(implode("\n", array_filter([
                    'Фото: '.($photosAdded > 0 ? "добавлено {$photosAdded}" : $result['photo']['reason']),
                    'Описание: '.($descAdded > 0 ? 'добавлено' : $result['description']['reason']),
                    'Характеристики: добавлено '.$attrsAdded,
                    'Цена, остаток и SKU не изменялись.',
                ])))
                ->status($importStatus === 'kaspi_imported' ? 'success' : 'warning')
                ->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Ошибка импорта Kaspi контента')
                ->body(mb_substr($exception->getMessage(), 0, 200))
                ->danger()
                ->send();
        }

        return true;
    }

    public function showImportCliCommand(Collection $products): bool
    {
        $ids = $products->pluck('id')->implode(',');
        $count = $products->count();

        $singleFlag = $count === 1
            ? '--product-id='.$products->pluck('id')->first()
            : '--ids='.$ids;

        Notification::make()
            ->title("Kaspi import — {$count} товаров (запустить в терминале):")
            ->body(
                "Dry-run (проверка):\n"
                .'php artisan kaspi:import-content '.$singleFlag.' --delay-ms=3000 --dry-run'
                ."\n\nРеальный импорт:\n"
                .'php artisan kaspi:import-content '.$singleFlag.' --delay-ms=3000 --force=true'
            )
            ->info()
            ->persistent()
            ->send();

        return true;
    }

    public function showSingleImportCliCommand(Product $product): bool
    {
        Notification::make()
            ->title('Kaspi import — запустить в терминале')
            ->body(
                'php artisan kaspi:import-content'
                .' --product-id='.$product->id
                .' --delay-ms=3000'
                .' --force=true'
                ."\n\nЕсли web/admin runner падает, используйте эту CLI-команду. Цена, остаток, SKU, категории, Paloma и заказы не меняются."
            )
            ->info()
            ->persistent()
            ->send();

        return true;
    }

    public function showMassImportCliCommand(): bool
    {
        Notification::make()
            ->title('Массовый импорт Kaspi контента (запустить в Laragon Console):')
            ->body(
                "Dry-run (проверка без сохранения):\n"
                .'php artisan kaspi:import-content --limit=10 --dry-run'
                ."\n\nРеальный импорт (50 товаров):\n"
                .'php artisan kaspi:import-content --limit=50 --delay-ms=3000 --force=true'
                ."\n\nПолный импорт всех:\n"
                .'php artisan kaspi:import-content --limit=0 --delay-ms=3000 --force=true'
            )
            ->info()
            ->persistent()
            ->send();

        return true;
    }
}

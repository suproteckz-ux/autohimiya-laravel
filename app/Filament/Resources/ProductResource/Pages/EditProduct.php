<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\KaspiEnrichmentTask;
use App\Services\Catalog\ProductThumbnailGenerator;
use App\Services\Kaspi\KaspiProductDiscoveryService;
use App\Support\ContentScore;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $original = $this->record->getOriginal();

        if (array_key_exists('name', $data) && ($original['name'] ?? null) !== ($data['name'] ?? null)) {
            $data['name_is_manual'] = true;
        }

        if (array_key_exists('category_id', $data) && (string) ($original['category_id'] ?? '') !== (string) ($data['category_id'] ?? '')) {
            $data['category_is_manual'] = true;
        }

        if (array_key_exists('description', $data) && ($original['description'] ?? null) !== ($data['description'] ?? null)) {
            $data['description_is_manual'] = true;
        }

        foreach (['slug', 'meta_title', 'meta_description', 'h1', 'canonical_url'] as $seoField) {
            if (array_key_exists($seoField, $data) && ($original[$seoField] ?? null) !== ($data[$seoField] ?? null)) {
                $data['seo_is_manual'] = true;
                break;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $data = $this->data ?? [];
        $flags = [];

        if (array_key_exists('images', $data)) {
            $flags['photos_are_manual'] = true;
        }

        if (array_key_exists('attributes', $data)) {
            $flags['attributes_are_manual'] = true;
        }

        if ($flags !== []) {
            $this->record->update($flags);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_storefront')
                ->label('Открыть на сайте')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => route('products.show', $this->record->slug))
                ->openUrlInNewTab(),
            Action::make('open_kaspi')
                ->label('Открыть Kaspi')
                ->icon('heroicon-o-shopping-bag')
                ->visible(fn (): bool => filled($this->record->kaspi_product_url))
                ->url(fn (): ?string => $this->record->kaspi_product_url)
                ->openUrlInNewTab(),
            Action::make('update_kaspi_content')
                ->label('Update Kaspi content')
                ->icon('heroicon-o-clipboard-document-check')
                ->action(function (): void {
                    KaspiEnrichmentTask::query()->updateOrCreate([
                        'product_id' => $this->record->id,
                        'kaspi_merchant_sku' => $this->record->sku,
                    ], [
                        'kaspi_product_url' => $this->record->kaspi_product_url,
                        'missing_photo' => ! ContentScore::hasPhoto($this->record),
                        'missing_description' => ! ContentScore::hasDescription($this->record),
                        'missing_attributes' => $this->record->attributes()->count() === 0,
                        'status' => 'pending',
                        'source' => 'admin',
                    ]);

                    Notification::make()->title('Kaspi content task created')->success()->send();
                }),
            ActionGroup::make([
                Action::make('check_kaspi')
                    ->label('Проверить Kaspi')
                    ->icon('heroicon-o-magnifying-glass')
                    ->action(function (): void {
                        $result = app(KaspiProductDiscoveryService::class)->discover($this->record, (bool) config('services.kaspi.dry_run', true));

                        Notification::make()
                            ->title('Kaspi: '.$result['status'])
                            ->body($result['error'] ?: ($result['url'] ?? 'Публичная карточка будет искаться по Product SKU.'))
                            ->success()
                            ->send();
                    }),
                Action::make('create_kaspi_task')
                    ->label('Kaspi content task')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->action(function (): void {
                        KaspiEnrichmentTask::query()->updateOrCreate([
                            'product_id' => $this->record->id,
                            'kaspi_merchant_sku' => $this->record->sku,
                        ], [
                            'kaspi_product_url' => $this->record->kaspi_product_url,
                            'missing_photo' => ! ContentScore::hasPhoto($this->record),
                            'missing_description' => ! ContentScore::hasDescription($this->record),
                            'missing_attributes' => $this->record->attributes()->count() === 0,
                            'status' => 'pending',
                            'source' => 'admin',
                        ]);

                        Notification::make()->title('Kaspi content task created')->success()->send();
                    }),
                Action::make('regenerate_thumbnails')
                    ->label('Regenerate thumbnails')
                    ->icon('heroicon-o-photo')
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $generator = app(ProductThumbnailGenerator::class);

                        $this->record->images()->get()->each(fn ($image) => $generator->make($image, true));
                    }),
            ])
                ->label('Ещё')
                ->icon('heroicon-o-ellipsis-vertical')
                ->color('gray'),
        ];
    }
}

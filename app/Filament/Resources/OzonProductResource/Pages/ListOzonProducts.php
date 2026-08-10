<?php

namespace App\Filament\Resources\OzonProductResource\Pages;

use App\Enums\OzonProductStatus;
use App\Filament\Resources\OzonProductResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOzonProducts extends ListRecords
{
    protected static string $resource = OzonProductResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Все'),
            'draft' => Tab::make('Черновики')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OzonProductStatus::Draft->value)),
            'ready' => Tab::make('Готовы')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', OzonProductStatus::Ready->value)),
            'failed' => Tab::make('Ошибки')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    OzonProductStatus::Failed->value,
                    OzonProductStatus::NeedsFix->value,
                    OzonProductStatus::Rejected->value,
                ])),
        ];
    }
}

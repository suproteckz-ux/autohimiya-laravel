<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;

class ListCategories extends ListRecords
{
    protected static string $resource = CategoryResource::class;

    protected Width | string | null $maxContentWidth = Width::Full;

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->recordAction(null)
            ->recordUrl(null);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Создать категорию')
                ->icon('heroicon-o-plus'),
            Action::make('expand_all')
                ->label('Развернуть все')
                ->icon('heroicon-o-arrows-pointing-out')
                ->color('gray')
                ->alpineClickHandler("
                    document.querySelectorAll('.category-tree-row').forEach((row) => row.classList.remove('hidden'));
                    window.dispatchEvent(new CustomEvent('category-tree-expand-all'));
                "),
            Action::make('collapse_all')
                ->label('Свернуть все')
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('gray')
                ->alpineClickHandler("
                    document.querySelectorAll('.category-tree-row').forEach((row) => {
                        row.classList.toggle('hidden', ! row.classList.contains('category-depth-0'));
                    });
                    window.dispatchEvent(new CustomEvent('category-tree-collapse-all'));
                "),
        ];
    }
}

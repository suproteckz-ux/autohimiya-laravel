<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected Width | string | null $maxContentWidth = Width::Full;
}

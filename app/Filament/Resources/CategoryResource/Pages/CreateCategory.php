<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    protected Width | string | null $maxContentWidth = Width::Full;
}

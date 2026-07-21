<?php

namespace App\Filament\Resources;

use BackedEnum;
use UnitEnum;

use App\Filament\Resources\GscPageResource\Pages;
use App\Models\GscPage;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GscPageResource extends Resource
{
    protected static ?string $model = GscPage::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';
    protected static string|UnitEnum|null $navigationGroup = 'SEO';
    protected static ?string $navigationLabel = 'GSC Analytics';
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('url')->searchable()->limit(70),
            TextColumn::make('date')->date()->sortable(),
            TextColumn::make('clicks')->sortable(),
            TextColumn::make('impressions')->sortable(),
            TextColumn::make('ctr')->sortable(),
            TextColumn::make('position')->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGscPages::route('/'),
        ];
    }
}

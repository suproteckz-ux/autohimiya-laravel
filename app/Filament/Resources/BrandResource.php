<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BrandResource\Pages;
use App\Models\Brand;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';
    protected static string | UnitEnum | null $navigationGroup = 'Каталог';
    protected static ?string $navigationLabel = 'Бренды';
    protected static ?string $modelLabel = 'бренд';
    protected static ?string $pluralModelLabel = 'бренды';
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required(),
            TextInput::make('slug')->label('SEO URL')->required(),
            TextInput::make('logo')->label('Логотип'),
            Select::make('status')
                ->label('Статус')
                ->options([
                    'active' => 'Активен',
                    'inactive' => 'Скрыт',
                ])
                ->required(),
            Textarea::make('description')->label('Описание')->columnSpanFull(),
            TextInput::make('meta_title')->label('Meta Title'),
            Textarea::make('meta_description')->label('Meta Description')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('name')->label('Название')->searchable(),
            TextColumn::make('status')
                ->label('Статус')
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'active' => 'Активен',
                    'inactive' => 'Скрыт',
                    default => $state ?: '—',
                })
                ->badge(),
            TextColumn::make('sort_order')->label('Сортировка')->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBrands::route('/'),
            'create' => Pages\CreateBrand::route('/create'),
            'edit' => Pages\EditBrand::route('/{record}/edit'),
        ];
    }
}

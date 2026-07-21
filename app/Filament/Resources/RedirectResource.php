<?php

namespace App\Filament\Resources;

use BackedEnum;
use UnitEnum;

use App\Filament\Resources\RedirectResource\Pages;
use App\Models\Redirect;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';
    protected static string|UnitEnum|null $navigationGroup = 'SEO';
    protected static ?string $navigationLabel = 'Редиректы';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('old_url')->required(),
            TextInput::make('new_url')->required(),
            TextInput::make('code')->numeric()->required(),
            TextInput::make('entity_type'),
            TextInput::make('entity_id')->numeric(),
            TextInput::make('source')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('old_url')->searchable()->limit(45),
            TextColumn::make('new_url')->searchable()->limit(45),
            TextColumn::make('code')->sortable(),
            TextColumn::make('source')->badge(),
            TextColumn::make('hit_count')->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRedirects::route('/'),
            'create' => Pages\CreateRedirect::route('/create'),
            'edit' => Pages\EditRedirect::route('/{record}/edit'),
        ];
    }
}

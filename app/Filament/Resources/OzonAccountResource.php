<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OzonAccountResource\Pages;
use App\Models\OzonAccount;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class OzonAccountResource extends Resource
{
    protected static ?string $model = OzonAccount::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static string|UnitEnum|null $navigationGroup = 'Ozon';
    protected static ?string $navigationLabel = 'Настройки кабинета';
    protected static ?string $modelLabel = 'кабинет Ozon';
    protected static ?string $pluralModelLabel = 'кабинеты Ozon';
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required()->maxLength(255), TextInput::make('client_id')->label('Client ID')->required()->maxLength(255),
            TextInput::make('api_key')->label('API key')->password()->dehydrated(fn (?string $state): bool => filled($state))->required(fn (string $operation): bool => $operation === 'create')->autocomplete('new-password'),
            Toggle::make('is_active')->label('Активен')->default(true), Toggle::make('is_test_mode')->label('Тестовый режим'),
            Select::make('fulfillment_scheme')->label('Схема работы')->options(['fbs' => 'FBS', 'fbo' => 'FBO', 'rfbs' => 'realFBS']),
            TextInput::make('default_price_multiplier')->label('Коэффициент цены')->numeric()->minValue(0.0001)->required()->default(1),
            Select::make('rounding_rule')->label('Округление')->options(['none'=>'Без округления','integer'=>'До целого','nearest_10'=>'До ближайших 10','nearest_100'=>'До ближайших 100','up_to_10'=>'Вверх до 10','up_to_100'=>'Вверх до 100'])->default('none'),
            TextInput::make('default_stock_limit')->label('Лимит остатка')->numeric()->minValue(0), TextInput::make('batch_size')->label('Размер партии')->numeric()->minValue(1)->required()->default(20),
            Toggle::make('sync_prices_enabled')->label('Синхронизация цены')->default(true), Toggle::make('sync_stocks_enabled')->label('Синхронизация остатка')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Название')->searchable(), TextColumn::make('client_id')->label('Client ID'), IconColumn::make('is_active')->label('Активен')->boolean(), IconColumn::make('is_test_mode')->label('Тестовый режим')->boolean(), TextColumn::make('default_price_multiplier')->label('Коэффициент цены'), TextColumn::make('default_stock_limit')->label('Лимит остатка'), TextColumn::make('batch_size')->label('Размер партии'), IconColumn::make('sync_prices_enabled')->label('Синхронизация цены')->boolean(), IconColumn::make('sync_stocks_enabled')->label('Синхронизация остатка')->boolean(), TextColumn::make('last_connection_check_at')->label('Проверка подключения')->dateTime(), TextColumn::make('created_at')->label('Создан')->dateTime()->sortable()]);
    }
    public static function getPages(): array { return ['index'=>Pages\ListOzonAccounts::route('/'),'create'=>Pages\CreateOzonAccount::route('/create'),'edit'=>Pages\EditOzonAccount::route('/{record}/edit')]; }
}

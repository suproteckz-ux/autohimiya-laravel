<?php

namespace App\Filament\Resources;

use App\Enums\AutomationRunSource;
use App\Enums\AutomationType;
use App\Filament\Resources\OzonAccountResource\Pages;
use App\Models\OzonAccount;
use App\Services\Automation\AutomationRunService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class OzonAccountResource extends Resource
{
    protected static ?string $model=OzonAccount::class;
    protected static string|BackedEnum|null $navigationIcon='heroicon-o-cog-6-tooth';
    protected static string|UnitEnum|null $navigationGroup='Ozon';
    protected static ?string $navigationLabel='Настройки кабинета';
    protected static ?string $modelLabel='кабинет Ozon';
    protected static ?string $pluralModelLabel='кабинеты Ozon';
    protected static ?int $navigationSort=30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            TextInput::make('client_id')->label('Client ID')->required()->maxLength(255),
            TextInput::make('api_key')->label('API key')->password()->dehydrated(fn(?string $state): bool=>filled($state))->required(fn(string $operation): bool=>$operation==='create')->autocomplete('new-password'),
            Toggle::make('is_active')->label('Активен')->default(true), Toggle::make('is_test_mode')->label('Тестовый режим'),
            Select::make('fulfillment_scheme')->label('Схема работы')->options(['fbs'=>'FBS','fbo'=>'FBO','rfbs'=>'realFBS']),
            TextInput::make('default_price_multiplier')->label('Коэффициент цены')->numeric()->minValue(0.0001)->required()->default(1),
            Select::make('rounding_rule')->label('Округление')->options(['none'=>'Без округления','integer'=>'До целого','nearest_10'=>'До ближайших 10','nearest_100'=>'До ближайших 100','up_to_10'=>'Вверх до 10','up_to_100'=>'Вверх до 100'])->default('none'),
            TextInput::make('default_stock_limit')->label('Лимит остатка')->numeric()->minValue(0), TextInput::make('batch_size')->label('Размер партии')->numeric()->minValue(1)->required()->default(20),
            Toggle::make('sync_prices_enabled')->label('Синхронизация цены')->default(true), Toggle::make('sync_stocks_enabled')->label('Синхронизация остатка')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')->label('Название')->searchable(),TextColumn::make('client_id')->label('Client ID'),IconColumn::make('is_active')->label('Активен')->boolean(),TextColumn::make('last_connection_check_at')->label('Последняя проверка')->dateTime(),TextColumn::make('last_connection_error')->label('Ошибка подключения')->limit(60)->wrap(),TextColumn::make('created_at')->label('Создан')->dateTime()->sortable()])
            ->recordActions([
                Action::make('checkConnection')->label('Проверить подключение')->action(fn(OzonAccount $record)=>self::requestReadOnlyRun($record,AutomationType::OzonConnectionCheck)),
                Action::make('loadWarehouses')->label('Загрузить склады')->action(fn(OzonAccount $record)=>self::requestReadOnlyRun($record,AutomationType::OzonWarehouseSync)),
                Action::make('loadTaxonomy')->label('Загрузить taxonomy')->action(fn(OzonAccount $record)=>self::requestReadOnlyRun($record,AutomationType::OzonTaxonomySync)),
            ]);
    }

    private static function requestReadOnlyRun(OzonAccount $account,AutomationType $type): void
    {
        $result=app(AutomationRunService::class)->request($type,AutomationRunSource::Admin,Auth::user(),['ozon_account_id'=>$account->id]);
        Notification::make()->title($result['created']?'Задача поставлена в очередь':'Такая задача уже ожидает выполнения')->success()->send();
    }

    public static function getPages(): array { return ['index'=>Pages\ListOzonAccounts::route('/'),'create'=>Pages\CreateOzonAccount::route('/create'),'edit'=>Pages\EditOzonAccount::route('/{record}/edit')]; }
}

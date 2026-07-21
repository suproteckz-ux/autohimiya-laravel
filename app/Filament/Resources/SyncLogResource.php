<?php

namespace App\Filament\Resources;

use BackedEnum;
use UnitEnum;

use App\Filament\Resources\SyncLogResource\Pages;
use App\Models\SyncLog;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SyncLogResource extends Resource
{
    protected static ?string $model = SyncLog::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';
    protected static string|UnitEnum|null $navigationGroup = 'Импорт / Синхронизация';
    protected static ?string $navigationLabel = 'Sync Logs';
    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('source')->disabled(),
            TextInput::make('mode')->disabled(),
            TextInput::make('command')->disabled()->columnSpanFull(),
            TextInput::make('status')->disabled(),
            KeyValue::make('payload_summary')->disabled()->columnSpanFull(),
            KeyValue::make('diagnostics')->disabled()->columnSpanFull(),
            KeyValue::make('raw_payload')->disabled()->columnSpanFull(),
            Textarea::make('error_message')->disabled()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('source')->badge(),
            TextColumn::make('mode')->badge(),
            TextColumn::make('command')->limit(80)->copyable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('created_count')->sortable(),
            TextColumn::make('updated_count')->sortable(),
            TextColumn::make('error_count')->sortable(),
            TextColumn::make('duration_ms')->label('ms')->sortable(),
            TextColumn::make('started_at')->dateTime()->sortable(),
        ])
            ->defaultSort('started_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->paginated([50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncLogs::route('/'),
            'view' => Pages\ViewSyncLog::route('/{record}'),
        ];
    }
}

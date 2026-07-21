<?php

namespace App\Filament\Resources;

use BackedEnum;
use UnitEnum;

use App\Filament\Resources\BlogPostResource\Pages;
use App\Models\BlogPost;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BlogPostResource extends Resource
{
    protected static ?string $model = BlogPost::class;
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-newspaper';
    protected static string|UnitEnum|null $navigationGroup = 'Контент';
    protected static ?string $navigationLabel = 'Блог';
    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            TextInput::make('slug')->required(),
            TextInput::make('image'),
            Textarea::make('excerpt')->columnSpanFull(),
            Textarea::make('content')->columnSpanFull(),
            TextInput::make('status')->required(),
            TextInput::make('meta_title'),
            Textarea::make('meta_description')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->searchable(),
            TextColumn::make('slug')->searchable(),
            TextColumn::make('status')->badge(),
            TextColumn::make('published_at')->dateTime()->sortable(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogPosts::route('/'),
            'create' => Pages\CreateBlogPost::route('/create'),
            'edit' => Pages\EditBlogPost::route('/{record}/edit'),
        ];
    }
}

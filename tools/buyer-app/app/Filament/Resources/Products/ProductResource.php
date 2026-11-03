<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedShoppingBag;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->required()]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->label('SKU')->color('gray')->fontFamily('mono')->searchable(),
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('price')->money('EUR')->alignEnd(),
                TextColumn::make('stock')->numeric()->alignEnd(),
                TextColumn::make('launch_date')->date('j M Y')->placeholder('Not set'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('sku');
    }

    public static function getPages(): array
    {
        return ['index' => ListProducts::route('/')];
    }
}

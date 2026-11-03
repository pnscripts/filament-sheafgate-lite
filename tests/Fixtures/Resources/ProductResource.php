<?php

namespace PNScripts\SheafgateLite\Tests\Fixtures\Resources;

use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use PNScripts\SheafgateLite\Tests\Fixtures\Models\Product;
use PNScripts\SheafgateLite\Tests\Fixtures\Resources\ProductResource\Pages\ListProducts;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    /** Lets a test reconfigure the import action. */
    public static ?Closure $configureImport = null;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('sku'),
            TextColumn::make('name'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
        ];
    }
}

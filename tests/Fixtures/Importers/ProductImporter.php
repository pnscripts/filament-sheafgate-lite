<?php

namespace PNScripts\SheafgateLite\Tests\Fixtures\Importers;

use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use PNScripts\SheafgateLite\Tests\Fixtures\Models\Product;

class ProductImporter extends Importer
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku')
                ->label('SKU')
                ->requiredMapping()
                ->rules(['required', 'max:20']),
            ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'max:80']),
            ImportColumn::make('price')
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),
            ImportColumn::make('launch_date')
                ->rules(['nullable', 'date']),
            ImportColumn::make('in_stock')
                ->boolean()
                ->rules(['boolean']),
            ImportColumn::make('barcode'),
            ImportColumn::make('category')
                ->relationship(resolveUsing: ['name', 'code']),
        ];
    }

    public function resolveRecord(): Product
    {
        return Product::query()->firstOrNew(['sku' => $this->data['sku']]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return "Imported {$import->successful_rows} of {$import->total_rows} products.";
    }
}

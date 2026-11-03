<?php

namespace App\Filament\Imports;

use App\Models\Product;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class ProductImporter extends Importer
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('sku')->label('SKU')->requiredMapping()->rules(['required', 'max:20'])->example('PN-1040'),
            ImportColumn::make('name')->requiredMapping()->rules(['required', 'max:80'])->example('Linen Cushion Cover'),
            ImportColumn::make('category')->relationship(resolveUsing: ['code', 'name'])->example('Home'),
            ImportColumn::make('price')->numeric()->rules(['nullable', 'numeric', 'min:0'])->example('24.00'),
            ImportColumn::make('stock')->integer()->rules(['nullable', 'integer', 'min:0'])->example('120'),
            ImportColumn::make('launch_date')->label('Launch date')->rules(['nullable', 'date'])->example('2026-03-01'),
            ImportColumn::make('is_active')->label('Active')->boolean()->rules(['boolean'])->example('yes'),
        ];
    }

    public function resolveRecord(): Product
    {
        return Product::query()->firstOrNew(['sku' => $this->data['sku']]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your product import has completed and ' . Number::format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }
}

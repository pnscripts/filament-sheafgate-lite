<?php

use PNScripts\SheafgateLite\Tests\Fixtures\Models\Product;
use PNScripts\SheafgateLite\Tests\Fixtures\Resources\ProductResource\Pages\ListProducts;

use function Pest\Livewire\livewire;

it('imports an XLSX workbook through Filament\'s importer', function () {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload('catalogue-libreoffice.xlsx')])
        ->assertActionDataSet([
            'sheafgate.sheet' => 'Products',
            'sheafgate.header_row' => 3,
            'columnMap.sku' => 'SKU',
            'columnMap.name' => 'Name',
            'columnMap.price' => 'Price',
            'columnMap.launch_date' => 'Launch date',
            'columnMap.in_stock' => 'In stock',
            'columnMap.barcode' => 'Barcode',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->orderBy('sku')->pluck('sku')->all())->toBe(['00123', '00124', '00125']);
});

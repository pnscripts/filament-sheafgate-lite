<?php

use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Forms\Components\Select;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use PNScripts\SheafgateLite\ImportAction;
use PNScripts\SheafgateLite\Tests\Fixtures\Models\Category;
use PNScripts\SheafgateLite\Tests\Fixtures\Models\Product;
use PNScripts\SheafgateLite\Tests\Fixtures\Models\User;
use PNScripts\SheafgateLite\Tests\Fixtures\Resources\ProductResource;
use PNScripts\SheafgateLite\Tests\Fixtures\Resources\ProductResource\Pages\ListProducts;


/**
 * The text under the upload field for the mounted import action.
 */
function describeUpload($component): ?string
{
    $action = $component->instance()->getMountedAction();
    $schema = $component->instance()->{$component->instance()->getMountedActionSchemaName()};
    $state = $schema->getFlatFields(withHidden: true)['file']->getState();
    $file = is_array($state) ? reset($state) : $state;
    $data = $action->getRawData();

    return $action->describeFile($file, $data['sheafgate']['sheet'] ?? null, $data['sheafgate']['header_row'] ?? null);
}

function configureImport(Closure $configure): void
{
    ProductResource::$configureImport = $configure;
}

it('imports XLSX and ODS workbooks through Filament\'s own importer', function (string $file) {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload($file)])
        ->assertActionDataSet([
            'sheafgate.sheet' => 'Products',
            'sheafgate.header_row' => 3,
            'columnMap.sku' => 'SKU',
            'columnMap.name' => 'Name',
            'columnMap.price' => 'Price',
            'columnMap.launch_date' => 'Launch date',
            'columnMap.in_stock' => 'In stock',
            'columnMap.barcode' => 'Barcode',
            'columnMap.category' => null,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $products = Product::query()->orderBy('sku')->get();

    expect($products->pluck('sku')->all())->toBe(['00123', '00124', '00125'])
        ->and($products->pluck('name')->all())->toBe(['Linen apron', 'Ceramic mug', 'Чаша за чай'])
        ->and($products->pluck('price')->all())->toBe(['19.90', '7.50', '0.10'])
        ->and($products->map(fn (Product $p): string => $p->launch_date->toDateString())->all())->toBe(['2026-03-01', '2026-04-15', '1999-12-31'])
        ->and($products->pluck('in_stock')->all())->toBe([true, false, true])
        ->and($products->pluck('barcode')->all())->toBe(['4006381333931', '12345678901234600000', '-42']);

    $import = Import::query()->sole();

    expect($import->total_rows)->toBe(3)
        ->and($import->successful_rows)->toBe(3)
        ->and($import->file_name)->toBe($file)
        ->and($import->completed_at)->not->toBeNull();
})->with(['catalogue-libreoffice.xlsx', 'catalogue-libreoffice.ods']);

it('shows the first value of each column next to its name in the mapping', function () {
    $component = livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload('catalogue-libreoffice.xlsx')]);

    $component->assertFormFieldExists('columnMap.price', function (Select $field): bool {
        expect($field->getOptions())->toMatchArray([
            'SKU' => 'SKU (e.g. 00123)',
            'Price' => 'Price (e.g. 19.9)',
            'Column I' => 'Column I (e.g. x)',
        ]);

        return true;
    });

    expect(describeUpload($component))->toBe('Sheet “Products”, column names in row 3.');
});

it('switches sheets, finding the header row and the mapping again', function () {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload('catalogue-libreoffice.xlsx')])
        ->setActionData(['sheafgate.sheet' => 'Categories'])
        ->assertActionDataSet([
            'sheafgate.header_row' => 1,
            'columnMap.sku' => null,
            'columnMap.name' => 'Name',
        ])
        ->setActionData(['sheafgate.sheet' => 'Products'])
        ->assertActionDataSet([
            'sheafgate.header_row' => 3,
            'columnMap.sku' => 'SKU',
        ]);
});

it('imports from the sheet and header row chosen in the modal', function () {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload('catalogue-libreoffice.ods')])
        ->setActionData(['sheafgate.sheet' => 'Categories'])
        ->setActionData(['columnMap' => ['sku' => 'Code', 'name' => 'Name']])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->orderBy('sku')->pluck('name', 'sku')->all())->toBe(['GAR' => 'Garden', 'KIT' => 'Kitchen']);
});

it('remaps when the header row is changed by hand', function () {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload('catalogue-libreoffice.xlsx')])
        ->setActionData(['sheafgate.header_row' => 4])
        ->assertActionDataSet(['columnMap.sku' => null, 'columnMap.name' => null])
        ->setActionData(['sheafgate.header_row' => 3])
        ->assertActionDataSet(['columnMap.sku' => 'SKU']);
});

it('reads a semicolon CSV in Windows-1252 and says how it was read', function () {
    $bytes = mb_convert_encoding("sku;name;price;in_stock\n001;Crème brûlée;4.50;yes\n002;Café;2.10;no\n", 'Windows-1252', 'UTF-8');

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => bytesUpload('menu.csv', $bytes)])
        ->assertActionDataSet(['columnMap.sku' => 'sku', 'columnMap.price' => 'price'])
        ->tap(fn ($component) => expect(describeUpload($component))->toBe('Read as Windows-1252 text, columns separated by semicolons. Column names in row 1.'))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->orderBy('sku')->get(['name', 'price', 'in_stock'])->toArray())->toBe([
        ['name' => 'Crème brûlée', 'price' => '4.50', 'in_stock' => true],
        ['name' => 'Café', 'price' => '2.10', 'in_stock' => false],
    ]);
});

it('reads Excel "Unicode text" (UTF-16, tabs) saved as .txt', function () {
    $bytes = "\xFF\xFE" . mb_convert_encoding("SKU\tName\n001\tЧаша\n", 'UTF-16LE', 'UTF-8');

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => bytesUpload('export.txt', $bytes)])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->sole()->only(['sku', 'name']))->toBe(['sku' => '001', 'name' => 'Чаша']);
});

it('resolves related records by name or code with Filament\'s relationship columns', function () {
    $kitchen = Category::query()->create(['name' => 'Kitchen', 'code' => 'KIT']);
    $garden = Category::query()->create(['name' => 'Garden', 'code' => 'GAR']);

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => bytesUpload('p.csv', "sku,name,category\n1,Apron,Kitchen\n2,Hose,GAR\n")])
        ->assertActionDataSet(['columnMap.category' => 'category'])
        ->callMountedAction();

    expect(Product::query()->orderBy('sku')->pluck('category_id')->all())->toBe([$kitchen->id, $garden->id]);
});

it('keeps Filament\'s validation, failed rows and their download', function () {
    $csv = "sku,name,price\n1,Apron,4.50\n2,,3\n3,Mug,-1\n";

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => bytesUpload('broken.csv', $csv)])
        ->callMountedAction();

    $import = Import::query()->sole();

    expect(Product::query()->pluck('sku')->all())->toBe(['1'])
        ->and($import->successful_rows)->toBe(1)
        ->and(FailedImportRow::query()->count())->toBe(2)
        ->and(FailedImportRow::query()->pluck('validation_error')->implode(' '))->toContain('name field is required')->toContain('price field must be at least 0');

    $url = URL::signedRoute('filament.imports.failed-rows.download', ['authGuard' => 'web', 'import' => $import], absolute: false);
    // Filament 5.8's sync import job signs the user out of the guard when it finishes; a real
    // download is a new request with its own session.
    $this->actingAs($this->user);
    $response = $this->get($url);
    $response->assertOk();

    expect($response->streamedContent())->toContain('sku,name,price,error')->toContain('3,Mug,-1');

    // Only the user who ran the import may download its failed rows.
    $this->actingAs(User::query()->create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'x']));
    $this->get($url)->assertForbidden();
});

it('respects maxRows() and chunkSize()', function () {
    configureImport(fn (ImportAction $action) => $action->maxRows(2));

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload('catalogue-libreoffice.xlsx')])
        ->callMountedAction()
        ->assertNotified('Uploaded CSV file is too large');

    expect(Product::query()->count())->toBe(0);

    configureImport(fn (ImportAction $action) => $action->chunkSize(1));

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload('catalogue-libreoffice.xlsx')])
        ->callMountedAction();

    expect(Product::query()->count())->toBe(3)
        ->and(DB::table('job_batches')->value('total_jobs'))->toBe(3);
});

it('uses the header row set with headerOffset() and can hide the header row field', function () {
    configureImport(fn (ImportAction $action) => $action->headerOffset(2)->headerRowField(false));

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => fixtureUpload('catalogue-libreoffice.xlsx')])
        ->assertActionDataSet(['sheafgate.header_row' => 3, 'columnMap.sku' => 'SKU'])
        ->assertFormFieldHidden('sheafgate.header_row')
        ->callMountedAction();

    expect(Product::query()->count())->toBe(3);
});

it('uses the CSV encoding and delimiter you set', function () {
    configureImport(fn (ImportAction $action) => $action->csvEncoding('ISO-8859-1')->csvDelimiter('|'));

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => bytesUpload('x.csv', mb_convert_encoding("sku|name\n1|Größe, groß\n", 'ISO-8859-1', 'UTF-8'))])
        ->callMountedAction();

    expect(Product::query()->sole()->name)->toBe('Größe, groß');
});

it('rejects files it cannot read, with a clear reason', function (string $name, string $bytes, string $message) {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => bytesUpload($name, $bytes)])
        ->assertHasFormErrors(['file'])
        ->tap(fn ($component) => expect(implode(' ', $component->errors()->all()))->toContain($message));
})->with([
    'xls' => ['old.xls', (string) file_get_contents(__DIR__ . '/../Fixtures/files/catalogue-libreoffice.xls'), 'Excel 97-2003 files (.xls) cannot be read.'],
    'wrong type' => ['photo.png', "\x89PNG\r\n\x1A\n", 'Upload an XLSX, ODS or CSV file.'],
    'empty file' => ['empty.csv', "\n\n", 'The sheet is empty.'],
]);

it('keeps Filament\'s example CSV download', function () {
    livewire(ListProducts::class)
        ->callAction(['import', 'downloadExample'])
        ->assertFileDownloaded('product-importer-example');
});

it('respects the action\'s authorization', function () {
    configureImport(fn (ImportAction $action) => $action->authorize('create'));

    livewire(ListProducts::class)->assertActionVisible('import');

    $this->user->update(['can_import' => false]);

    livewire(ListProducts::class)->assertActionHidden('import');
});

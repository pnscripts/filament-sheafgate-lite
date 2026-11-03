<?php

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

const EXPECTED_PRODUCTS = [
    4 => ['SKU' => '00123', 'Name' => 'Linen apron', 'Price' => '19.9', 'Launch date' => '2026-03-01', 'In stock' => '1', 'Updated at' => '2026-03-01 14:30:00', 'Opens' => '09:30:00', 'Total' => '39.8', 'Column I' => 'x', 'Barcode' => '4006381333931', 'Discount' => '0.15'],
    5 => ['SKU' => '00124', 'Name' => 'Ceramic mug', 'Price' => '7.5', 'Launch date' => '2026-04-15', 'In stock' => '0', 'Updated at' => '2026-04-15 08:05:09', 'Opens' => '18:00:00', 'Total' => '15', 'Column I' => '', 'Barcode' => '12345678901234600000', 'Discount' => '0'],
    7 => ['SKU' => '00125', 'Name' => 'Чаша за чай', 'Price' => '0.1', 'Launch date' => '1999-12-31', 'In stock' => '1', 'Updated at' => '2000-01-01', 'Opens' => '00:00:00', 'Total' => '0.2', 'Column I' => '', 'Barcode' => '-42', 'Discount' => '1'],
];

function writeXlsx(array $sheets, ?callable $configure = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'sheafgate-xlsx-') . '.xlsx';
    $writer = new XlsxWriter;
    $writer->openToFile($path);
    $first = true;

    foreach ($sheets as $name => $rows) {
        $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $configure && $configure($sheet, $name);
        $first = false;

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
    }

    $writer->close();

    return $path;
}

it('reads LibreOffice workbooks as text: dates, booleans, formulas, leading zeros, huge numbers', function (string $file, string $format) {
    $reader = SpreadsheetReader::open(fixturePath($file));
    $table = $reader->table('Products');

    expect($reader->format)->toBe($format)
        ->and($table->headerRow)->toBe(3)
        ->and($table->headers)->toBe(array_keys(EXPECTED_PRODUCTS[4]))
        ->and(iterator_to_array($table->rows()))->toBe(EXPECTED_PRODUCTS);
})->with([
    'xlsx' => ['catalogue-libreoffice.xlsx', 'xlsx'],
    'ods' => ['catalogue-libreoffice.ods', 'ods'],
]);

it('lists the sheets in workbook order', function (string $file) {
    expect(SpreadsheetReader::open(fixturePath($file))->sheets())->toBe(['Products', 'Merged header', 'Categories', 'Empty']);
})->with(['catalogue-libreoffice.xlsx', 'catalogue-libreoffice.ods']);

it('names the columns under a merged header cell', function (string $file) {
    $table = SpreadsheetReader::open(fixturePath($file))->table('Merged header');

    expect($table->headers)->toBe(['Item', 'Size', 'Size (2)', 'Unit price'])
        ->and(iterator_to_array($table->rows()))->toBe([2 => ['Item' => 'Box', 'Size' => '10', 'Size (2)' => '20', 'Unit price' => '3.5']]);
})->with(['catalogue-libreoffice.xlsx', 'catalogue-libreoffice.ods']);

it('reads dates of the 1904 date system', function () {
    $rows = iterator_to_array(SpreadsheetReader::open(fixturePath('orders-1904.xlsx'))->table()->rows());

    expect($rows)->toBe([2 => ['Order' => 'A-1', 'Ordered on' => '2026-03-01', 'Ships at' => '2026-03-02 16:45:00']]);
});

it('refuses an empty sheet', function (string $file) {
    SpreadsheetReader::open(fixturePath($file))->table('Empty');
})->with(['catalogue-libreoffice.xlsx', 'catalogue-libreoffice.ods'])
    ->throws(UnreadableSpreadsheet::class, 'The sheet is empty.');

it('explains that .xls files must be saved as .xlsx', function () {
    SpreadsheetReader::open(fixturePath('catalogue-libreoffice.xls'), 'catalogue.xls');
})->throws(UnreadableSpreadsheet::class, 'Excel 97-2003 files (.xls) cannot be read.');

it('refuses a ZIP archive that is not a workbook', function () {
    $path = tempnam(sys_get_temp_dir(), 'sheafgate-zip-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'hello');
    $zip->close();

    SpreadsheetReader::open($path, 'products.xlsx');
})->throws(UnreadableSpreadsheet::class, 'not an XLSX or ODS workbook');

it('refuses a damaged workbook with a clear message', function () {
    $bytes = (string) file_get_contents(fixturePath('catalogue-libreoffice.xlsx'));
    $path = tempFileWith(substr($bytes, 0, 2000), '.xlsx');

    SpreadsheetReader::open($path, 'damaged.xlsx')->table();
})->throws(UnreadableSpreadsheet::class, 'This file could not be read.');

it('refuses text renamed to .xlsx', function () {
    SpreadsheetReader::open(tempFileWith("SKU,Name\n1,2\n", '.xlsx'), 'products.xlsx');
})->throws(UnreadableSpreadsheet::class, 'This file could not be read.');

it('starts on the first sheet that has data and skips hidden sheets', function () {
    $path = writeXlsx([
        'Cover' => [],
        'Secret' => [['A', 'B'], ['1', '2']],
        'Data' => [['SKU', 'Name'], ['1', 'Apron']],
    ], function ($sheet, string $name): void {
        if ($name === 'Secret') {
            $sheet->setIsVisible(false);
        }
    });

    $reader = SpreadsheetReader::open($path);

    expect($reader->sheets())->toBe(['Cover', 'Data'])
        ->and($reader->firstSheetWithData())->toBe('Data')
        ->and($reader->table()->headers)->toBe(['SKU', 'Name']);
});

it('uses the header row you choose', function () {
    $table = SpreadsheetReader::open(fixturePath('catalogue-libreoffice.xlsx'))->table('Products', 4);

    expect($table->headerRow)->toBe(4)
        ->and($table->headers[0])->toBe('00123')
        ->and(array_keys(iterator_to_array($table->rows())))->toBe([5, 7]);
});

it('refuses an empty header row', function () {
    SpreadsheetReader::open(fixturePath('catalogue-libreoffice.xlsx'))->table('Products', 6);
})->throws(UnreadableSpreadsheet::class, 'The chosen header row is empty.');

it('pads short rows and ignores cells right of the last header', function () {
    $path = writeXlsx(['Data' => [
        ['SKU', 'Name', 'Price'],
        ['1'],
        ['2', 'Mug', '3', 'stray', 'cells'],
    ]]);

    expect(iterator_to_array(SpreadsheetReader::open($path)->table()->rows()))->toBe([
        2 => ['SKU' => '1', 'Name' => '', 'Price' => ''],
        3 => ['SKU' => '2', 'Name' => 'Mug', 'Price' => '3'],
    ]);
});

it('ignores a million repeated empty rows in an ODS file', function () {
    $path = tempnam(sys_get_temp_dir(), 'sheafgate-ods-');
    $content = '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet>'
        . '<table:table table:name="Sheet1">'
        . '<table:table-row><table:table-cell office:value-type="string"><text:p>Name</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>Note</text:p></table:table-cell><table:table-cell table:number-columns-repeated="16382"/></table:table-row>'
        . '<table:table-row table:number-rows-repeated="1048000"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
        . '<table:table-row><table:table-cell office:value-type="string"><text:p>Far<text:s text:c="2"/>down</text:p><text:p>second line</text:p></table:table-cell><table:table-cell office:value-type="string" office:string-value="from attribute"/></table:table-row>'
        . '<table:table-row table:number-rows-repeated="574"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
        . '</table:table></office:spreadsheet></office:body></office:document-content>';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
    $zip->addFromString('content.xml', $content);
    $zip->close();

    $started = microtime(true);
    $rows = iterator_to_array(SpreadsheetReader::open($path)->table()->rows());

    expect($rows)->toBe([1048002 => ['Name' => "Far  down\nsecond line", 'Note' => 'from attribute']])
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});

it('streams a large workbook', function () {
    $rows = [['SKU', 'Name', 'Price']];

    for ($i = 1; $i <= 5000; $i++) {
        $rows[] = [sprintf('%05d', $i), "Product {$i}", $i / 4];
    }

    $table = SpreadsheetReader::open(writeXlsx(['Data' => $rows]))->table();
    $count = 0;
    $last = null;

    foreach ($table->rows() as $number => $row) {
        $count++;
        $last = [$number, $row];
    }

    expect($count)->toBe(5000)
        ->and($last)->toBe([5001, ['SKU' => '05000', 'Name' => 'Product 5000', 'Price' => '1250']]);
});

it('writes the clean CSV that Filament reads', function () {
    $stream = SpreadsheetReader::open(fixturePath('catalogue-libreoffice.ods'))->table('Merged header')->toCsvStream();

    expect(stream_get_contents($stream))->toBe("Item,Size,\"Size (2)\",\"Unit price\"\nBox,10,20,3.5\n");
});

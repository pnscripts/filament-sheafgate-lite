<?php

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

/*
 * Rows and cells a hostile file can declare cheaply but a reader pays for dearly
 * (security review round 5, 04.11.2026): rows whose only value lies far right of the
 * header, rows with a million cell elements, CSV lines with a million fields.
 */

const WIDTH_HEADER = '<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="B1" t="inlineStr"><is><t>Name</t></is></c></row>';

afterEach(function () {
    SpreadsheetReader::$maxRows = SpreadsheetReader::DEFAULT_MAX_ROWS;
});

/**
 * An ODS package around the given table rows, with the SKU / Name header row first.
 */
function odsWithRows(string $rows): string
{
    return zipWith([
        'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
        'content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet><table:table table:name="Data">'
            . '<table:table-row><table:table-cell office:value-type="string"><text:p>SKU</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>Name</text:p></table:table-cell></table:table-row>'
            . $rows . '</table:table></office:spreadsheet></office:body></office:document-content>',
    ], '.ods');
}

it('counts rows whose only value lies right of the header toward the row limit', function (string $path) {
    SpreadsheetReader::$maxRows = 100;

    iterator_to_array(SpreadsheetReader::open($path, basename($path))->table()->rows());
})->with([
    'xlsx' => fn () => zipWith(xlsxWithSheet(WIDTH_HEADER . implode('', array_map(fn (int $r): string => "<row r=\"{$r}\"><c r=\"XFD{$r}\" t=\"inlineStr\"><is><t>x</t></is></c></row>", range(2, 400))))),
    'ods' => fn () => odsWithRows(str_repeat('<table:table-row><table:table-cell table:number-columns-repeated="16383"/><table:table-cell office:value-type="string"><text:p>x</text:p></table:table-cell></table:table-row>', 400)),
    'csv' => fn () => tempFileWith("SKU,Name\n" . str_repeat(",,x\n", 400)),
])->throws(UnreadableSpreadsheet::class, 'more than 100 rows');

it('keeps only the header\'s columns of a row, never padding it to the far value', function (string $path) {
    $reader = SpreadsheetReader::open($path, basename($path));
    $sheet = $reader->firstSheetWithData();
    $widths = array_map('count', iterator_to_array($reader->cells($sheet, 2)));

    expect(max($widths))->toBeLessThanOrEqual(2);
})->with([
    'xlsx' => fn () => zipWith(xlsxWithSheet(WIDTH_HEADER . '<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c><c r="XFD2" t="inlineStr"><is><t>far</t></is></c></row>')),
    'ods' => fn () => odsWithRows('<table:table-row><table:table-cell office:value-type="string"><text:p>1</text:p></table:table-cell><table:table-cell table:number-columns-repeated="16382"/><table:table-cell office:value-type="string"><text:p>far</text:p></table:table-cell></table:table-row>'),
    'csv' => fn () => tempFileWith("SKU,Name\n1," . str_repeat(',', 5000) . "far\n"),
]);

it('still reads the header\'s columns of a row that also has a far value', function () {
    $path = zipWith(xlsxWithSheet(WIDTH_HEADER . '<row r="2"><c r="XFD2" t="inlineStr"><is><t>far</t></is></c><c r="A2" t="inlineStr"><is><t>PN-1</t></is></c><c r="B2" t="inlineStr"><is><t>Mug</t></is></c></row>'));

    expect(iterator_to_array(SpreadsheetReader::open($path, 'far.xlsx')->table()->rows()))
        ->toBe([2 => ['SKU' => 'PN-1', 'Name' => 'Mug']]);
});

it('refuses a row with more cells than a sheet has columns', function (string $path) {
    $started = microtime(true);

    try {
        iterator_to_array(SpreadsheetReader::open($path, basename($path))->table()->rows());
        $this->fail('The row was read.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->getMessage())->toContain('XFD')
            ->and(microtime(true) - $started)->toBeLessThan(5.0);
    }
})->with([
    // All on one column: the cell references never pass XFD, only the count does.
    'xlsx, one column' => fn () => zipWith(xlsxWithSheet(WIDTH_HEADER . '<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c>' . str_repeat('<c r="B2"><v>7</v></c>', 20000) . '</row>')),
    'ods, covered cells' => fn () => odsWithRows('<table:table-row><table:table-cell office:value-type="string"><text:p>1</text:p></table:table-cell>' . str_repeat('<table:covered-table-cell/>', 20000) . '</table:table-row>'),
    'csv' => fn () => tempFileWith("SKU,Name\n1," . str_repeat('7,', 20000) . "7\n"),
]);

it('allows a full-width row of exactly XFD cells', function () {
    $cells = '';

    for ($column = 0; $column < 16384; $column++) {
        $cells .= '<c r="' . \PNScripts\SheafgateLite\Reader\Headers::letter($column) . '2"><v>' . $column . '</v></c>';
    }

    $path = zipWith(xlsxWithSheet(WIDTH_HEADER . '<row r="2">' . $cells . '</row>'));

    expect(iterator_to_array(SpreadsheetReader::open($path, 'full.xlsx')->table()->rows()))
        ->toBe([2 => ['SKU' => '0', 'Name' => '1']]);
});

it('reads a merged header in an ODS file read cell by cell', function () {
    $path = zipWith([
        'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
        'content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet><table:table table:name="Data">'
            . '<table:table-row table:number-rows-repeated="2"/>'
            . '<table:table-row><table:table-cell office:value-type="string"><text:p>SKU</text:p></table:table-cell><table:table-cell table:number-columns-spanned="2" office:value-type="string"><text:p>Size</text:p></table:table-cell><table:covered-table-cell/><table:table-cell office:value-type="string"><text:p>Price</text:p></table:table-cell></table:table-row>'
            . '<table:table-row><table:table-cell office:value-type="string"><text:p>A</text:p></table:table-cell><table:table-cell office:value-type="float" office:value="1"/><table:table-cell office:value-type="float" office:value="2"/><table:table-cell office:value-type="float" office:value="3.5"/></table:table-row>'
            . '</table:table></office:spreadsheet></office:body></office:document-content>',
    ], '.ods');

    $table = SpreadsheetReader::open($path, 'merged.ods')->table(null, 3);

    expect($table->headers)->toBe(['SKU', 'Size', 'Size (2)', 'Price'])
        ->and(iterator_to_array($table->rows()))->toBe([4 => ['SKU' => 'A', 'Size' => '1', 'Size (2)' => '2', 'Price' => '3.5']]);
});

it('reads only the shared strings the rows ask for', function () {
    $path = zipWithLargePart(xlsxWithSheet(WIDTH_HEADER . '<row r="2"><c r="A2" t="s"><v>0</v></c><c r="B2" t="s"><v>1</v></c></row>', ''), 'xl/sharedStrings.xml', (function () {
        yield '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        for ($i = 0; $i < 1900000; $i += 10000) {
            $piece = '';

            for ($j = $i; $j < $i + 10000; $j++) {
                $piece .= "<si><t>string number {$j}</t></si>";
            }

            yield $piece;
        }

        yield '</sst>';
    })());

    // Reading all 1.9 million strings needs far more memory than this.
    [$code, $output] = readInChildProcess($path, '32M');

    expect($code)->toBe(0, $output)
        ->and($output)->toBe('rows=1');
});

it('refuses more than 2,000,000 shared strings', function () {
    $path = zipWithLargePart(xlsxWithSheet(WIDTH_HEADER . '<row r="2"><c r="A2" t="s"><v>2000000</v></c><c r="B2" t="s"><v>1</v></c></row>', ''), 'xl/sharedStrings.xml', (function () {
        yield '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        for ($i = 0; $i <= 2000000; $i += 10000) {
            $piece = '';

            for ($j = $i; $j < min($i + 10000, 2000001); $j++) {
                $piece .= "<si><t>{$j}</t></si>";
            }

            yield $piece;
        }

        yield '</sst>';
    })());

    // The archive passes the size check: the strings themselves are refused.
    $reader = SpreadsheetReader::open($path, 'strings.xlsx');

    iterator_to_array($reader->table()->rows());
})->throws(UnreadableSpreadsheet::class, 'too large');

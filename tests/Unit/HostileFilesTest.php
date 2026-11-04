<?php

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

const HEADER_ROW = '<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="B1" t="inlineStr"><is><t>Name</t></is></c></row>';

it('counts the bytes really unpacked, not the sizes the archive states', function () {
    $sheet = (function () {
        yield '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . HEADER_ROW;

        for ($i = 0; $i < 20; $i++) {
            yield str_repeat('<row/>', 100000);
        }

        yield '</sheetData></worksheet>';
    })();
    $path = lieAboutSize(zipWithLargePart(xlsxWithSheet(''), 'xl/worksheets/sheet1.xml', $sheet), 'xl/worksheets/sheet1.xml');

    $started = microtime(true);

    try {
        SpreadsheetReader::open($path, 'lie.xlsx')->table();
        $this->fail('The lying archive was read.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->getMessage())->toContain('too large')
            ->and(microtime(true) - $started)->toBeLessThan(5.0);
    }
});

it('refuses shared strings that unpack far beyond their packed size', function () {
    $strings = (function () {
        yield '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        for ($i = 0; $i < 20; $i++) {
            yield str_repeat('<si><t>' . str_repeat('x', 100) . '</t></si>', 10000);
        }

        yield '</sst>';
    })();
    $path = lieAboutSize(zipWithLargePart(xlsxWithSheet(HEADER_ROW, ''), 'xl/sharedStrings.xml', $strings), 'xl/sharedStrings.xml');

    SpreadsheetReader::open($path, 'sst.xlsx')->sheets();
})->throws(UnreadableSpreadsheet::class, 'too large');

it('refuses a cell longer than a spreadsheet allows', function () {
    $cell = '<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c><c r="B2" t="inlineStr"><is><t>' . str_repeat('y', 40000) . '</t></is></c></row>';
    $path = zipWith(xlsxWithSheet(HEADER_ROW . $cell));

    iterator_to_array(SpreadsheetReader::open($path, 'long.xlsx')->table()->rows());
})->throws(UnreadableSpreadsheet::class, '32,767');

it('turns broken XML into a clear message instead of an error page', function (string $suffix, array $entries) {
    SpreadsheetReader::open(zipWith($entries, $suffix), 'broken' . $suffix)->table();
})->with([
    'xlsx' => ['.xlsx', xlsxWithSheet(HEADER_ROW . '<row r="2"><c r="A2"><v>1</v')],
    'ods' => ['.ods', ['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet', 'content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"><office:body><office:spreadsheet><table:table table:name="S"><table:table-row><table:table-cell']],
])->throws(UnreadableSpreadsheet::class, 'could not be read');

it('parses an upload once, not on every look at it', function () {
    $path = zipWith(xlsxWithSheet(HEADER_ROW . '<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c></row>'));
    SpreadsheetReader::$parses = 0;

    foreach (range(1, 3) as $request) {
        $reader = SpreadsheetReader::open($path, 'x.xlsx');
        $reader->sheets();
        $reader->preview($reader->firstSheetWithData());
    }

    expect(SpreadsheetReader::$parses)->toBe(1);
});

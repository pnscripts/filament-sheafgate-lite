<?php

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

// Findings of security review round 6 (2026-11-04): each test failed on the code before the fix.

const R6_HEADER = '<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="B1" t="inlineStr"><is><t>Name</t></is></c></row>';

afterEach(function () {
    SpreadsheetReader::$maxRows = SpreadsheetReader::DEFAULT_MAX_ROWS;

    if (property_exists(SpreadsheetReader::class, 'maxRowElements')) {
        SpreadsheetReader::$maxRowElements = SpreadsheetReader::DEFAULT_MAX_ROW_ELEMENTS;
    }

    if (property_exists(SpreadsheetReader::class, 'maxCellElements')) {
        SpreadsheetReader::$maxCellElements = SpreadsheetReader::DEFAULT_MAX_CELL_ELEMENTS;
    }
});

function r6Ods(string $rows, string $tables = ''): string
{
    return zipWith([
        'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
        'content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet>' . $tables . '<table:table table:name="Data">'
            . '<table:table-row><table:table-cell office:value-type="string"><text:p>SKU</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>Name</text:p></table:table-cell></table:table-row>'
            . $rows . '</table:table></office:spreadsheet></office:body></office:document-content>',
    ], '.ods');
}

/**
 * An XLSX package whose workbook lists the given sheets, all pointing at their own target.
 *
 * @param  array<string, string>  $sheets  name => relationship target (relative to xl/)
 * @param  array<string, string>  $parts  extra entries
 */
function r6Workbook(array $sheets, array $parts): string
{
    $list = '';
    $rels = '';
    $id = 0;

    foreach ($sheets as $name => $target) {
        $id++;
        $list .= '<sheet name="' . $name . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
        $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . $target . '"/>';
    }

    $entries = xlsxWithSheet('');
    $entries['xl/workbook.xml'] = '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $list . '</sheets></workbook>';
    $entries['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    unset($entries['xl/worksheets/sheet1.xml']);

    return zipWith([...$entries, ...$parts]);
}

function r6Sheet(string $rows): string
{
    return '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>';
}

// 1. The preview padded whitespace-only far cells to column XFD.

it('never pads a row without a width to a whitespace-only far cell', function (string $path) {
    $reader = SpreadsheetReader::open($path, basename($path));
    $rows = iterator_to_array($reader->cells($reader->firstSheetWithData()));

    expect($rows[1])->toBe(['SKU', 'Name'])
        ->and(count($rows[2] ?? []))->toBe(0);
})->with([
    'xlsx' => fn () => zipWith(xlsxWithSheet(R6_HEADER . '<row r="2"><c r="XFD2" t="inlineStr"><is><t> </t></is></c></row>')),
    'ods' => fn () => r6Ods('<table:table-row><table:table-cell table:number-columns-repeated="16000"/><table:table-cell office:value-type="string"><text:p><text:s/></text:p></table:table-cell></table:table-row>'),
]);

it('previews a sheet of repeated whitespace-only rows in moments', function () {
    $path = r6Ods(str_repeat('<table:table-row table:number-rows-repeated="10000"><table:table-cell table:number-columns-repeated="16000"/><table:table-cell office:value-type="string"><text:p><text:s/></text:p></table:table-cell></table:table-row>', 2));
    $started = microtime(true);
    $reader = SpreadsheetReader::open($path, 'pad.ods');

    expect($reader->preview($reader->firstSheetWithData()))->toBe([1 => ['SKU', 'Name']])
        ->and(microtime(true) - $started)->toBeLessThan(3.0);
});

it('reads a chosen header row without padding the rows above it', function () {
    $far = '';

    for ($r = 1; $r <= 6000; $r++) {
        $far .= "<row r=\"{$r}\"><c r=\"XFD{$r}\" t=\"inlineStr\"><is><t>x</t></is></c></row>";
    }

    $path = zipWith(xlsxWithSheet($far . '<row r="6001"><c r="A6001" t="inlineStr"><is><t>SKU</t></is></c></row><row r="6002"><c r="A6002" t="inlineStr"><is><t>PN-1</t></is></c></row>'));
    $started = microtime(true);
    $table = SpreadsheetReader::open($path, 'far.xlsx')->table(null, 6001);

    expect($table->headers[0])->toBe('SKU')
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});

// 2. The archive check measured only .xml, .rels and mimetype parts.

it('measures every part of the archive, whatever its name', function (string $part) {
    $entries = xlsxWithSheet(R6_HEADER);
    $bomb = (function () {
        yield '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        for ($i = 0; $i < 6; $i++) {
            yield str_repeat('<row r="2"><c/></row>', 100000);
        }

        yield '</sheetData></worksheet>';
    })();

    if ($part === 'sheet') {
        $entries['xl/_rels/workbook.xml.rels'] = str_replace('worksheets/sheet1.xml', 'worksheets/sheet1.bin', $entries['xl/_rels/workbook.xml.rels']);
        $name = 'xl/worksheets/sheet1.bin';
    } else {
        $entries['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.dat"/></Relationships>', $entries['xl/_rels/workbook.xml.rels']);
        $name = 'xl/styles.dat';
    }

    SpreadsheetReader::open(zipWithLargePart($entries, $name, $bomb), 'bomb.xlsx');
})->with(['sheet', 'styles'])->throws(UnreadableSpreadsheet::class, 'too large');

// 3. LIBXML_PARSEHUGE lifted libxml's depth limit, and deep nesting inside a cell crashed PHP.

it('refuses deep nesting inside a cell instead of crashing PHP', function (string $path) {
    [$code, $output] = readInChildProcess($path);

    expect($code)->toBe(0, $output)
        ->and($output)->toStartWith('refused: ');
})->with([
    'xlsx' => fn () => zipWith(xlsxWithSheet(R6_HEADER . '<row r="2"><c r="A2" t="inlineStr"><is>' . str_repeat('<r>', 50000) . '<t>x</t>' . str_repeat('</r>', 50000) . '</is></c></row>')),
    'ods' => fn () => r6Ods('<table:table-row><table:table-cell office:value-type="string"><text:p>' . str_repeat('<text:span>', 50000) . 'x' . str_repeat('</text:span>', 50000) . '</text:p></table:table-cell></table:table-row>'),
]);

// 4. Many sheets pointing at one part, each parsed in full; empty rows never counted.

it('parses a part shared by many sheets once', function () {
    $sheets = [];

    for ($i = 1; $i <= 50; $i++) {
        $sheets["S{$i}"] = 'worksheets/sheet1.xml';
    }

    $path = r6Workbook($sheets, ['xl/worksheets/sheet1.xml' => r6Sheet(str_repeat('<row r="1"><c/></row>', 2000))]);
    $reader = SpreadsheetReader::open($path, 'many.xlsx');
    $before = SpreadsheetReader::$parses;
    $reader->firstSheetWithData();

    expect(SpreadsheetReader::$parses - $before)->toBeLessThanOrEqual(1);
});

it('counts empty row elements toward one budget per reader', function () {
    SpreadsheetReader::$maxRowElements = 1000;
    $path = zipWith(xlsxWithSheet(R6_HEADER . str_repeat('<row r="2"/>', 2000) . '<row r="3"><c r="A3" t="inlineStr"><is><t>1</t></is></c></row>'));

    iterator_to_array(SpreadsheetReader::open($path, 'empty-rows.xlsx')->table()->rows());
})->throws(UnreadableSpreadsheet::class, 'rows');

it('refuses a workbook that lists more sheets than any real one', function () {
    $sheets = [];

    for ($i = 1; $i <= SpreadsheetReader::MAX_SHEETS + 1; $i++) {
        $sheets["S{$i}"] = 'worksheets/sheet1.xml';
    }

    SpreadsheetReader::open(r6Workbook($sheets, ['xl/worksheets/sheet1.xml' => r6Sheet(R6_HEADER)]), 'sheets.xlsx')->sheets();
})->throws(UnreadableSpreadsheet::class);

// 5. ODS <text:s text:c="2000000000"/> ran str_repeat with 2 GB.

it('refuses a huge ODS space count instead of running out of memory', function () {
    $path = r6Ods('<table:table-row><table:table-cell office:value-type="string"><text:p>a<text:s text:c="2000000000"/>b</text:p></table:table-cell></table:table-row>');

    [$code, $output] = readInChildProcess($path);

    expect($code)->toBe(0, $output)
        ->and($output)->toMatch('/^refused: .*(characters|cell_too_long)/');
});

// 7. CSV: blank records never counted; cells not capped.

it('counts blank CSV records toward the scanned-row cap', function () {
    $path = tempFileWith("SKU,Name\n" . str_repeat("\n", 250000) . "1,Mug\n");
    $started = microtime(true);

    try {
        iterator_to_array(SpreadsheetReader::open($path, 'blank.csv')->table()->rows());
        $this->fail('The file was read.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->getMessage())->toContain('rows')
            ->and(microtime(true) - $started)->toBeLessThan(5.0);
    }
});

it('still reads a CSV with a few blank lines, with the file\'s row numbers', function () {
    $path = tempFileWith("SKU,Name\n\n1,Mug\n\n\n2,Cup\n");

    expect(iterator_to_array(SpreadsheetReader::open($path, 'gaps.csv')->table()->rows()))
        ->toBe([3 => ['SKU' => '1', 'Name' => 'Mug'], 6 => ['SKU' => '2', 'Name' => 'Cup']]);
});

it('refuses a CSV cell longer than a spreadsheet cell can be', function () {
    $path = tempFileWith("SKU,Name\n1,\"" . str_repeat('a', SpreadsheetReader::MAX_CELL_LENGTH + 1) . "\"\n");

    iterator_to_array(SpreadsheetReader::open($path, 'long.csv')->table()->rows());
})->throws(UnreadableSpreadsheet::class, 'characters');

// 8. The 1900 date system: serials 1-59 read one day early.

it('reads the first two months of 1900 as Excel shows them', function () {
    $entries = xlsxWithSheet('<row r="1"><c r="A1" t="inlineStr"><is><t>Date</t></is></c></row>'
        . '<row r="2"><c r="A2" s="1"><v>1</v></c></row>'
        . '<row r="3"><c r="A3" s="1"><v>59</v></c></row>'
        . '<row r="4"><c r="A4" s="1"><v>61</v></c></row>'
        . '<row r="5"><c r="A5" s="1"><v>45658.5</v></c></row>'
        . '<row r="6"><c r="A6" s="1"><v>0.25</v></c></row>');
    $entries['xl/styles.xml'] = '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="22"/></cellXfs></styleSheet>';

    $dates = array_column(iterator_to_array(SpreadsheetReader::open(zipWith($entries), 'dates.xlsx')->table()->rows()), 'Date');

    expect($dates)->toBe(['1900-01-01', '1900-02-28', '1900-03-01', '2025-01-01 12:00:00', '06:00:00']);
});

// Found while re-running the kit: a sheet of millions of empty cells (250 MB of <c/> from a
// 2.8 MB upload, kept under the 100x ratio) ran past 200 s. Cell elements now share one budget.

it('counts empty cells toward one budget per reader', function (string $path) {
    SpreadsheetReader::$maxCellElements = 100000;
    $started = microtime(true);

    try {
        $reader = SpreadsheetReader::open($path, basename($path));
        iterator_to_array($reader->table($reader->firstSheetWithData())->rows());
        $this->fail('The sheet was read.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->getMessage())->toContain('cells')
            ->and(microtime(true) - $started)->toBeLessThan(3.0);
    }
})->with([
    'xlsx' => fn () => zipWith(xlsxWithSheet(R6_HEADER . str_repeat('<row r="2">' . str_repeat('<c/>', 10000) . '</row>', 30))),
    'ods' => fn () => r6Ods(str_repeat('<table:table-row>' . str_repeat('<table:table-cell/>', 10000) . '</table:table-row>', 30)),
]);

it('still reads empty cells and an empty error cell as before', function () {
    $path = zipWith(xlsxWithSheet(R6_HEADER . '<row r="2"><c r="A2" t="inlineStr"><is><t>PN-1</t></is></c><c r="B2" s="0"/></row><row r="3"><c r="A3" t="inlineStr"><is><t>PN-2</t></is></c><c r="B3" t="e"/></row>'));

    $rows = iterator_to_array(SpreadsheetReader::open($path, 'empty.xlsx')->table()->rows());

    expect($rows[2])->toBe(['SKU' => 'PN-1', 'Name' => ''])
        ->and($rows[3]['Name'])->toBe(\PNScripts\SheafgateLite\Reader\CellValue::ERROR);
});

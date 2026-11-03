<?php

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

const LIMITS_HEADER = '<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="B1" t="inlineStr"><is><t>Name</t></is></c></row>';

it('refuses a cell beyond the last spreadsheet column instead of running out of memory', function () {
    $path = zipWith(xlsxWithSheet(LIMITS_HEADER . '<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c><c r="ZZZZZZ2" t="inlineStr"><is><t>x</t></is></c></row>'));

    [$code, $output] = readInChildProcess($path);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('refused');
});

it('refuses a row beyond the last spreadsheet row', function () {
    $path = zipWith(xlsxWithSheet(LIMITS_HEADER . '<row r="1048577"><c r="A1048577" t="inlineStr"><is><t>1</t></is></c></row>'));

    iterator_to_array(SpreadsheetReader::open($path, 'rows.xlsx')->table()->rows());
})->throws(UnreadableSpreadsheet::class);

it('reads millions of shared strings within a 128 MB memory limit', function () {
    $strings = '';
    $cells = '';

    for ($i = 0; $i < 3000000; $i++) {
        $strings .= "<si><t>string number {$i}</t></si>";
    }

    for ($i = 2; $i <= 1001; $i++) {
        $cells .= "<row r=\"{$i}\"><c r=\"A{$i}\" t=\"s\"><v>" . ($i * 2999) . "</v></c><c r=\"B{$i}\" t=\"s\"><v>{$i}</v></c></row>";
    }

    $path = zipWith(xlsxWithSheet(LIMITS_HEADER . $cells, $strings));
    unset($strings);

    [$code, $output] = readInChildProcess($path);

    // Either read, or refused with a clear message: never a fatal out-of-memory error.
    expect($code)->toBe(0, $output)
        ->and($output)->toMatch('/^(rows=1000|refused: .+)$/');
});

it('keeps its cache in the app\'s storage and forgets an upload', function () {
    $path = zipWith(xlsxWithSheet(LIMITS_HEADER . '<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c></row>'));
    $reader = SpreadsheetReader::open($path, 'x.xlsx');
    $reader->preview($reader->firstSheetWithData());

    expect(SpreadsheetReader::cacheDirectory())->toStartWith(storage_path('framework'))
        ->and(glob(SpreadsheetReader::cacheDirectory() . '/' . sha1($path) . '-*'))->not->toBeEmpty();

    SpreadsheetReader::forget($path);

    expect(glob(SpreadsheetReader::cacheDirectory() . '/' . sha1($path) . '-*'))->toBeEmpty();
});

it('refuses archives with too many parts before unpacking them', function () {
    $entries = xlsxWithSheet(LIMITS_HEADER);

    for ($i = 0; $i < 6000; $i++) {
        $entries["xl/media/pad{$i}.bin"] = 'x';
    }

    $started = microtime(true);

    try {
        SpreadsheetReader::open(zipWith($entries), 'parts.xlsx');
        $this->fail('Accepted');
    } catch (UnreadableSpreadsheet) {
        expect(microtime(true) - $started)->toBeLessThan(2.0);
    }
});

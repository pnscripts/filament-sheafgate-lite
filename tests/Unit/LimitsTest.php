<?php

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

afterEach(function () {
    SpreadsheetReader::$maxEntryBytes = SpreadsheetReader::DEFAULT_MAX_ENTRY_BYTES;
    SpreadsheetReader::$maxRows = SpreadsheetReader::DEFAULT_MAX_ROWS;
});

it('refuses a workbook that unpacks to more than the limit before parsing it', function () {
    SpreadsheetReader::$maxEntryBytes = 1024 * 1024;
    $path = zipWith(['xl/workbook.xml' => '<workbook/>', 'xl/worksheets/sheet1.xml' => str_repeat('<row/>', 400000)]);

    expect(filesize($path))->toBeLessThan(100000);

    SpreadsheetReader::open($path, 'bomb.xlsx');
})->throws(UnreadableSpreadsheet::class, 'too large');

it('stops reading after the row limit', function () {
    SpreadsheetReader::$maxRows = 3;
    $table = SpreadsheetReader::open(tempFileWith("a,b\n1,2\n3,4\n5,6\n7,8\n"), 'x.csv')->table();

    iterator_to_array($table->rows());
})->throws(UnreadableSpreadsheet::class, 'more than 3 rows');

it('allows the row limit itself', function () {
    SpreadsheetReader::$maxRows = 3;
    $rows = iterator_to_array(SpreadsheetReader::open(tempFileWith("a,b\n1,2\n3,4\n5,6\n"), 'x.csv')->table()->rows());

    expect($rows)->toHaveCount(3);
});

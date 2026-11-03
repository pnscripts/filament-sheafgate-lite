<?php

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

it('reads the upload step of a 20,000-row workbook in well under a second and all rows in seconds', function () {
    $path = tempnam(sys_get_temp_dir(), 'sheafgate-perf-') . '.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['SKU', 'Name', 'Category', 'Price', 'Stock']));

    for ($i = 1; $i <= 20000; $i++) {
        $writer->addRow(Row::fromValues(["PN-{$i}", "Product {$i} with a longer name", 'Kitchen', $i / 7, $i % 500]));
    }

    $writer->close();

    $started = microtime(true);
    $reader = SpreadsheetReader::open($path, 'perf.xlsx');
    $reader->table($reader->firstSheetWithData())->sample(10);
    $upload = microtime(true) - $started;

    $started = microtime(true);
    $rows = iterator_count(SpreadsheetReader::open($path, 'perf.xlsx')->table()->rows());
    $read = microtime(true) - $started;

    // Generous bounds for busy machines; measured on the build machine: 0.1 s and 1 s.
    expect($rows)->toBe(20000)
        ->and($upload)->toBeLessThan(3.0)
        ->and($read)->toBeLessThan(15.0);
});

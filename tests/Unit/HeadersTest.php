<?php

use PNScripts\SheafgateLite\Reader\HeaderDetector;
use PNScripts\SheafgateLite\Reader\Headers;

it('names blank header cells after their column letter', function () {
    expect(Headers::normalize(['SKU', '', 'Price', '']))->toBe(['SKU', 'Column B', 'Price']);
});

it('numbers duplicate headers, ignoring case', function () {
    expect(Headers::normalize(['Name', 'name', 'Name', 'Name (2)']))->toBe(['Name', 'name (2)', 'Name (3)', 'Name (2) (2)']);
});

it('folds line breaks and runs of spaces in header names', function () {
    expect(Headers::normalize(["Unit\nprice", '  Launch   date ', "Tab\there"]))->toBe(['Unit price', 'Launch date', 'Tab here']);
});

it('gives the cells of a merged header the merged name', function () {
    expect(Headers::normalize(['Item', 'Size', '', '', 'Price'], [[1, 3]]))->toBe(['Item', 'Size', 'Size (2)', 'Size (3)', 'Price']);
});

it('does not overwrite filled cells inside a merge', function () {
    expect(Headers::normalize(['Size', 'Width'], [[0, 1]]))->toBe(['Size', 'Width']);
});

it('turns column numbers into letters and back', function (int $index, string $letters) {
    expect(Headers::letter($index))->toBe($letters)
        ->and(Headers::columnIndex($letters))->toBe($index);
})->with([[0, 'A'], [25, 'Z'], [26, 'AA'], [701, 'ZZ'], [702, 'AAA']]);

it('finds the header row below title rows by the importer\'s column names', function () {
    $rows = [
        1 => ['Spring price list 2026'],
        3 => ['SKU', 'Name', 'Price'],
        4 => ['00123', 'Linen apron', '19.9'],
    ];

    expect(HeaderDetector::detect($rows, ['sku' => ['sku'], 'name' => ['name']]))->toBe(3);
});

it('finds the header row without guesses: the first full row of text', function () {
    $rows = [
        1 => ['Exported 2026-11-03'],
        2 => ['Order', 'Customer', 'Total'],
        3 => ['A-1', 'Ivan', '12.5'],
    ];

    expect(HeaderDetector::detect($rows))->toBe(2);
});

it('falls back to the first non-empty row', function () {
    expect(HeaderDetector::detect([5 => ['1', '2'], 6 => ['3', '4']]))->toBe(5)
        ->and(HeaderDetector::detect([]))->toBeNull();
});

it('prefers the row matching most importer columns over an earlier partial match', function () {
    $rows = [
        1 => ['Name of the list'],
        2 => ['Name', 'Notes'],
        3 => ['SKU', 'Name', 'Price'],
    ];

    expect(HeaderDetector::detect($rows, ['sku' => ['sku'], 'name' => ['name'], 'price' => ['price']]))->toBe(3);
});

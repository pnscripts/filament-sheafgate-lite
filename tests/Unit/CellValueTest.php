<?php

use OpenSpout\Common\Entity\Cell;
use PNScripts\SheafgateLite\Reader\CellValue;

it('writes numbers the way a spreadsheet shows them', function (float $value, string $expected) {
    expect(CellValue::number($value))->toBe($expected);
})->with([
    'decimal' => [19.9, '19.9'],
    'float noise' => [0.1 + 0.2, '0.3'],
    'whole float' => [42.0, '42'],
    'negative' => [-1234.5, '-1234.5'],
    'negative zero' => [-0.0, '0'],
    'tiny' => [1.5e-7, '0.00000015'],
    'fifteen digits' => [1e15, '1000000000000000'],
    'twenty digits' => [12345678901234567890.0, '12345678901234600000'],
    'large exponent' => [1e20, '100000000000000000000'],
    'many decimals' => [123456789.123456789, '123456789.123457'],
    'negative tiny' => [-2.5e-5, '-0.000025'],
]);

it('never writes NaN or infinity', function () {
    expect(CellValue::number(NAN))->toBe('')
        ->and(CellValue::number(INF))->toBe('');
});

it('writes dates, date-times and times of day', function () {
    expect(CellValue::toText(new DateTimeImmutable('2026-03-01 00:00:00')))->toBe('2026-03-01')
        ->and(CellValue::toText(new DateTimeImmutable('2026-03-01 14:30:05')))->toBe('2026-03-01 14:30:05')
        ->and(CellValue::toText(new DateTimeImmutable('1899-12-30 09:30:00')))->toBe('09:30:00')
        ->and(CellValue::toText(new DateTimeImmutable('1899-12-31 18:00:00')))->toBe('18:00:00')
        ->and(CellValue::toText(new DateTimeImmutable('1904-01-01 07:15:00')))->toBe('07:15:00')
        ->and(CellValue::toText(new DateTimeImmutable('1904-01-01 00:00:00')))->toBe('1904-01-01');
});

it('writes durations as hours, minutes and seconds', function () {
    expect(CellValue::toText(new DateInterval('PT9H30M')))->toBe('09:30:00')
        ->and(CellValue::toText((new DateTimeImmutable('2026-01-01'))->diff(new DateTimeImmutable('2026-01-02 12:00:01'))))->toBe('36:00:01');
});

it('writes booleans as 1 and 0, which Filament\'s boolean columns accept', function () {
    expect(CellValue::toText(true))->toBe('1')
        ->and(CellValue::toText(false))->toBe('0')
        ->and(CellValue::toText(Cell::fromValue(true)))->toBe('1');
});

it('uses the saved result of a formula, not the formula', function () {
    expect(CellValue::toText(new Cell\FormulaCell('=A1*2', null, 39.8)))->toBe('39.8')
        ->and(CellValue::toText(new Cell\FormulaCell('=A1&"x"', null, 'abc')))->toBe('abc')
        ->and(CellValue::toText(new Cell\FormulaCell('=1/0', null, null)))->toBe('');
});

it('keeps text exactly, leading zeros included', function () {
    expect(CellValue::toText(Cell::fromValue('00123')))->toBe('00123')
        ->and(CellValue::toText(Cell::fromValue('  spaced  ')))->toBe('  spaced  ')
        ->and(CellValue::toText(null))->toBe('')
        ->and(CellValue::toText(new Cell\ErrorCell('#DIV/0!', null)))->toBe('#DIV/0!');
});

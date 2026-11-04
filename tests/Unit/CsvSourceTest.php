<?php

use PNScripts\SheafgateLite\Reader\CsvSource;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

function csvRows(string $bytes, ?string $encoding = null, ?string $delimiter = null): array
{
    $reader = SpreadsheetReader::open(tempFileWith($bytes), 'file.csv', $encoding, $delimiter);
    $table = $reader->table();

    return ['headers' => $table->headers, 'rows' => iterator_to_array($table->rows()), 'encoding' => $table->encoding, 'delimiter' => $table->delimiter];
}

it('reads UTF-8 with and without a byte order mark', function () {
    $plain = csvRows("SKU,Name\n001,Чаша\n");
    $bom = csvRows("\xEF\xBB\xBFSKU,Name\n001,Чаша\n");

    expect($plain['rows'])->toBe([2 => ['SKU' => '001', 'Name' => 'Чаша']])
        ->and($bom['rows'])->toBe($plain['rows'])
        ->and($bom['headers'][0])->toBe('SKU')
        ->and($bom['encoding'])->toBe('UTF-8');
});

it('reads UTF-16 little and big endian, with or without a byte order mark', function (string $bytes, string $encoding) {
    $result = csvRows($bytes);

    expect($result['encoding'])->toBe($encoding)
        ->and($result['rows'])->toBe([2 => ['SKU' => '001', 'Name' => 'Café €5']]);
})->with([
    'LE with BOM (Excel "Unicode text")' => ["\xFF\xFE" . mb_convert_encoding("SKU\tName\n001\tCafé €5\n", 'UTF-16LE', 'UTF-8'), 'UTF-16LE'],
    'BE with BOM' => ["\xFE\xFF" . mb_convert_encoding("SKU;Name\n001;Café €5\n", 'UTF-16BE', 'UTF-8'), 'UTF-16BE'],
    'LE without BOM' => [mb_convert_encoding("SKU,Name\n001,Café €5\n", 'UTF-16LE', 'UTF-8'), 'UTF-16LE'],
    'BE without BOM' => [mb_convert_encoding("SKU,Name\n001,Café €5\n", 'UTF-16BE', 'UTF-8'), 'UTF-16BE'],
]);

it('reads Windows-1252 text from older Excel exports', function () {
    $result = csvRows(mb_convert_encoding("SKU;Name;Note\n001;Crème brûlée;€ 4\n", 'Windows-1252', 'UTF-8'));

    expect($result['encoding'])->toBe('Windows-1252')
        ->and($result['rows'][2])->toBe(['SKU' => '001', 'Name' => 'Crème brûlée', 'Note' => '€ 4']);
});

it('tells Windows-1251 Cyrillic apart from Windows-1252', function () {
    $result = csvRows(mb_convert_encoding("Код;Име;Цена\n001;Чаша за чай;4.50\n002;Тенджера;19.90\n", 'Windows-1251', 'UTF-8'));

    expect($result['encoding'])->toBe('Windows-1251')
        ->and($result['headers'])->toBe(['Код', 'Име', 'Цена'])
        ->and($result['rows'][3]['Име'])->toBe('Тенджера');
});

it('detects the delimiter', function (string $bytes, string $delimiter) {
    $result = csvRows($bytes);

    expect($result['delimiter'])->toBe($delimiter)
        ->and($result['headers'])->toBe(['SKU', 'Name', 'Price']);
})->with([
    'comma' => ["SKU,Name,Price\n1,\"Apron, linen\",2\n", ','],
    'semicolon' => ["SKU;Name;Price\n1;Apron, linen;2,5\n2;Mug;3,5\n", ';'],
    'tab' => ["SKU\tName\tPrice\n1\tApron\t2\n", "\t"],
    'pipe' => ["SKU|Name|Price\n1|Apron|2\n", '|'],
]);

it('keeps quoted delimiters and line breaks inside cells', function () {
    $result = csvRows("SKU,Name,Notes\n1,\"Apron, linen\",\"two\nlines\"\n2,Mug,\n");

    expect($result['rows'][2])->toBe(['SKU' => '1', 'Name' => 'Apron, linen', 'Notes' => "two\nlines"])
        ->and($result['rows'][3]['Name'])->toBe('Mug');
});

it('uses the encoding and delimiter you set', function () {
    $result = csvRows(mb_convert_encoding("A;B\nä;ö\n", 'Windows-1252', 'UTF-8'), 'ISO-8859-1', ';');

    expect($result['encoding'])->toBe('ISO-8859-1')
        ->and($result['rows'][2])->toBe(['A' => 'ä', 'B' => 'ö']);
});

it('normalises encoding names', function () {
    expect(CsvSource::canonicalEncoding('cp1251'))->toBe('Windows-1251')
        ->and(CsvSource::canonicalEncoding('utf8'))->toBe('UTF-8')
        ->and(CsvSource::canonicalEncoding('UTF-16'))->toBe('UTF-16LE');
});

it('keeps a UTF-8 character split by the sample boundary as UTF-8', function () {
    // 90 000 bytes in 30,000 characters (under the 32,767-character cell limit): the 64 KiB
    // sample ends inside a character.
    $line = str_repeat('€', 30000);
    $result = csvRows("A,B\n1,{$line}\n");

    expect($result['encoding'])->toBe('UTF-8')
        ->and($result['rows'][2]['B'])->toBe($line);
});

it('skips blank lines and finds the header below a title line', function () {
    $result = csvRows("Price list\n\nSKU,Name,Price\n1,Apron,2\n\n2,Mug,3\n");

    expect($result['headers'])->toBe(['SKU', 'Name', 'Price'])
        ->and(array_keys($result['rows']))->toBe([4, 6]);
});

<?php

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

// Findings of security review round 7 (2026-11-04): each test failed on the code before the fix.
// Memory is measured in a child process (peak RSS under a 2 GB address-space cap), because
// libxml's memory lies outside PHP's memory_limit.

const R7_HEADER = '<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="B1" t="inlineStr"><is><t>Name</t></is></c></row>';

afterEach(function () {
    foreach (['maxImportBytes' => 'DEFAULT_MAX_IMPORT_BYTES', 'maxNodes' => 'DEFAULT_MAX_NODES', 'maxCellElements' => 'DEFAULT_MAX_CELL_ELEMENTS'] as $property => $default) {
        if (property_exists(SpreadsheetReader::class, $property) && defined(SpreadsheetReader::class . '::' . $default)) {
            SpreadsheetReader::${$property} = constant(SpreadsheetReader::class . '::' . $default);
        }
    }
});

function r7Ods(string $rows): string
{
    return zipWith([
        'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
        'content.xml' => '<?xml version="1.0"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet><table:table table:name="Data">'
            . $rows . '</table:table></office:spreadsheet></office:body></office:document-content>',
    ], '.ods');
}

function r7Refusal(Closure $read): string
{
    try {
        $read();
    } catch (UnreadableSpreadsheet $exception) {
        return $exception->getMessage();
    }

    return 'read without a refusal';
}

// 1. Width amplification: every row was padded and stored at the header's width, up to 16,384.

it('refuses a header as wide as a sheet instead of padding every row to it', function (string $path) {
    $started = microtime(true);
    $message = r7Refusal(fn () => iterator_count(SpreadsheetReader::open($path, basename($path))->table()->rows()));

    expect($message)->toContain('more than 1,024 columns')
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
})->with([
    'xlsx: A1 and XFD1' => function () {
        $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="XFD1" t="inlineStr"><is><t>Last</t></is></c></row>';

        for ($r = 2; $r <= 500; $r++) {
            $rows .= "<row r=\"{$r}\"><c r=\"XFD{$r}\" t=\"inlineStr\"><is><t>x</t></is></c></row>";
        }

        return zipWith(xlsxWithSheet($rows));
    },
    'ods: 16 cells repeated 1,024 times' => fn () => r7Ods(
        '<table:table-row>' . str_repeat('<table:table-cell table:number-columns-repeated="1024" office:value-type="string"><text:p>h</text:p></table:table-cell>', 16) . '</table:table-row>'
        . '<table:table-row table:number-rows-repeated="9999">' . str_repeat('<table:table-cell table:number-columns-repeated="1024" office:value-type="string"><text:p>x</text:p></table:table-cell>', 16) . '</table:table-row>'
    ),
]);

it('never takes a repeated or wide row for the header, and keeps rows at the header\'s width', function () {
    $path = r7Ods(
        '<table:table-row><table:table-cell office:value-type="string"><text:p>SKU</text:p></table:table-cell><table:table-cell office:value-type="string"><text:p>Name</text:p></table:table-cell></table:table-row>'
        . str_repeat('<table:table-row table:number-rows-repeated="9999">' . str_repeat('<table:table-cell table:number-columns-repeated="1024" office:value-type="string"><text:p>x</text:p></table:table-cell>', 16) . '</table:table-row>', 3)
    );
    $started = microtime(true);
    $table = SpreadsheetReader::open($path, 'narrow.ods')->table();

    expect($table->headers)->toBe(['SKU', 'Name']);

    $rows = 0;

    foreach ($table->rows() as $row) {
        $rows++;
        expect(count($row))->toBe(2);
    }

    expect($rows)->toBe(29997)
        ->and(microtime(true) - $started)->toBeLessThan(10.0);
});

it('accepts a header of 1,024 columns and refuses one of 1,025', function () {
    $csv = fn (int $columns): string => tempFileWith(implode(',', array_map(fn (int $i): string => "c{$i}", range(1, $columns))) . "\n" . implode(',', range(1, $columns)) . "\n");

    expect(count(SpreadsheetReader::open($csv(1024), 'a.csv')->table()->headers))->toBe(1024)
        ->and(r7Refusal(fn () => SpreadsheetReader::open($csv(1025), 'b.csv')->table()))->toContain('more than 1,024 columns');
});

it('stops an import that would store more than its byte budget', function () {
    SpreadsheetReader::$maxImportBytes = 200000;
    $csv = "sku,name\n";

    for ($i = 1; $i <= 2000; $i++) {
        $csv .= $i . ',' . str_repeat('n', 100) . "\n";
    }

    $path = tempFileWith($csv);

    expect(r7Refusal(fn () => iterator_count(SpreadsheetReader::open($path, 'big.csv')->table()->rows())))->toContain('MB of data to import');
});

it('counts repeated ODS cells and rows as often as they are expanded', function () {
    SpreadsheetReader::$maxCellElements = 20000;
    $path = r7Ods(
        '<table:table-row><table:table-cell table:number-columns-repeated="1000" office:value-type="string"><text:p>h</text:p></table:table-cell></table:table-row>'
        . '<table:table-row table:number-rows-repeated="100"><table:table-cell table:number-columns-repeated="1000" office:value-type="string"><text:p>x</text:p></table:table-cell></table:table-row>'
    );

    expect(r7Refusal(fn () => iterator_count(SpreadsheetReader::open($path, 'rep.ods')->table(null, 1)->rows())))->toContain('cells to read');
});

// 2. libxml memory outside memory_limit: expand() of whole cells, comments and processing
//    instructions kept by XMLReader.

it('refuses floods of comments and processing instructions before libxml reads them', function (string $where) {
    $flood = str_repeat('<?a?><!---->', 600000);
    $rows = match ($where) {
        'between rows' => R7_HEADER . '<row r="2"><c r="A2"><v>1</v></c></row>' . $flood . '<row r="3"><c r="A3"><v>2</v></c></row>',
        'inside a cell' => R7_HEADER . '<row r="2"><c r="A2"><v>1</v>' . $flood . '</c></row>',
    };
    $path = zipWith(xlsxWithSheet($rows));
    unset($flood, $rows);

    [$code, $output, $rss] = readInChildProcess($path);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('too_complex')
        ->and($rss)->toBeLessThan(100 * 1024);
})->with(['between rows', 'inside a cell']);

it('never builds a cell as a DOM: a cell of a million junk children is refused', function () {
    $path = zipWith(xlsxWithSheet(R7_HEADER . '<row r="2"><c r="A2"><v>1</v>' . str_repeat('<x/>', 1000000) . '</c></row>'));

    [$code, $output, $rss] = readInChildProcess($path);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('too_complex')
        ->and($rss)->toBeLessThan(100 * 1024);
});

it('refuses a cell of rich-text runs without building it', function () {
    $path = zipWith(xlsxWithSheet(R7_HEADER . '<row r="2"><c r="A2" t="inlineStr"><is>' . str_repeat('<r><t>a</t></r>', 300000) . '</is></c></row>'));

    [$code, $output, $rss] = readInChildProcess($path);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('too_complex')
        ->and($rss)->toBeLessThan(100 * 1024);
});

it('refuses a part with a token longer than any real cell before libxml parses it', function () {
    $styles = '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="200" formatCode="' . str_repeat('0', 9000000) . '"/></numFmts><cellXfs count="1"><xf numFmtId="200"/></cellXfs></styleSheet>';
    $entries = xlsxWithSheet(R7_HEADER . '<row r="2"><c r="A2" s="0"><v>1</v></c></row>');
    $entries['xl/styles.xml'] = $styles;
    $entries['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>', $entries['xl/_rels/workbook.xml.rels']);
    $path = zipWith($entries);
    unset($styles, $entries);

    [$code, $output, $rss] = readInChildProcess($path);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('too_complex')
        ->and($rss)->toBeLessThan(90 * 1024);
});

it('counts every node, text and whitespace included, against one budget per reader', function () {
    SpreadsheetReader::$maxNodes = 50000;
    $path = zipWith(xlsxWithSheet(R7_HEADER . '<row r="2"><c r="A2"><v>1</v></c>' . str_repeat(' <x/>', 100000) . '<c r="B2"><v>2</v></c></row>'));

    expect(r7Refusal(fn () => iterator_count(SpreadsheetReader::open($path, 'nodes.xlsx')->table()->rows())))->toContain('XML markup');
});

it('allows nodes in proportion to the values read', function () {
    $budget = new \PNScripts\SheafgateLite\Reader\Budget(maxNodes: 32000000, maxMarkup: 10, maxRows: 10, maxCells: 10, rowLimit: 10);
    $base = $budget->allowance;
    $budget->value();

    expect($base)->toBe(4000000)
        ->and($budget->allowance)->toBe(4000024);
});

// 3. Fatal errors at memory_limit 128M (numFmt codes, rich text, shared strings, CSV lines).

it('gathers inline and shared string text in bounded steps', function (string $kind) {
    $run = str_repeat('a', 400000);
    $runs = str_repeat("<r><t>{$run}</t></r>", 24);
    $path = zipWith($kind === 'inline'
        ? xlsxWithSheet(R7_HEADER . '<row r="2"><c r="A2" t="inlineStr"><is>' . $runs . '</is></c></row>')
        : xlsxWithSheet(R7_HEADER . '<row r="2"><c r="A2" t="s"><v>0</v></c></row>', '<si>' . $runs . '</si>'));
    unset($run, $runs);

    [$code, $output] = readInChildProcess($path, '16M');

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('cell_too_long');
})->with(['inline', 'shared']);

it('detects the delimiter of one huge line in bounded memory', function () {
    $path = tempFileWith(str_repeat(',', 3000000));

    [$code, $output] = readInChildProcess($path, '32M', 'line.csv');

    expect($code)->toBe(0, $output)
        ->and($output)->toStartWith('refused: ');
});

it('ignores number format codes longer than Excel allows', function () {
    $styles = '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="200" formatCode="yyyy-mm-dd' . str_repeat('0', 300) . '"/></numFmts><cellXfs count="1"><xf numFmtId="200"/></cellXfs></styleSheet>';
    $entries = xlsxWithSheet(R7_HEADER . '<row r="2"><c r="A2" s="0"><v>45000</v></c><c r="B2" t="inlineStr"><is><t>x</t></is></c></row>');
    $entries['xl/styles.xml'] = $styles;
    $entries['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>', $entries['xl/_rels/workbook.xml.rels']);
    $rows = iterator_to_array(SpreadsheetReader::open(zipWith($entries), 'fmt.xlsx')->table()->rows());

    expect($rows[2]['SKU'])->toBe('45000');
});

// 5. CSV: a lone CR merged rows silently; UTF-32 was read as UTF-16; NUL bytes got through.

it('reads a lone CR as a line break, the same as LF and CRLF, and keeps one inside quotes', function () {
    $rows = iterator_to_array(SpreadsheetReader::open(tempFileWith("sku,name\n1,A\n2,Ж\r3,B\r\n4,\"x\ry\"\n"), 'cr.csv')->table()->rows());

    expect(array_values($rows))->toBe([
        ['sku' => '1', 'name' => 'A'],
        ['sku' => '2', 'name' => 'Ж'],
        ['sku' => '3', 'name' => 'B'],
        ['sku' => '4', 'name' => "x\ry"],
    ]);
});

it('reads a file with only CR line breaks', function () {
    $rows = iterator_to_array(SpreadsheetReader::open(tempFileWith("sku,name\r1,A\r2,B\r"), 'cr-only.csv')->table()->rows());

    expect(count($rows))->toBe(2);
});

it('decodes UTF-32 with a byte order mark', function (string $encoding, string $bom) {
    $path = tempFileWith($bom . mb_convert_encoding("sku,name\n1,Ж€\n", $encoding, 'UTF-8'));
    $reader = SpreadsheetReader::open($path, 'u32.csv');
    $rows = iterator_to_array($reader->table()->rows());

    expect($reader->csvEncoding())->toBe($encoding)
        ->and(array_values($rows))->toBe([['sku' => '1', 'name' => 'Ж€']]);
})->with([
    'little endian' => ['UTF-32LE', "\xFF\xFE\x00\x00"],
    'big endian' => ['UTF-32BE', "\x00\x00\xFE\xFF"],
]);

it('refuses NUL bytes in a CSV file', function () {
    $path = tempFileWith("sku,name\n1,A\0B\n");

    expect(r7Refusal(fn () => iterator_count(SpreadsheetReader::open($path, 'nul.csv')->table()->rows())))->toContain('UTF-8');
});

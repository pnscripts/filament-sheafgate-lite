<?php

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\Budget;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

// Findings of security review round 8 (2026-11-04): each test failed on the code before the fix.
// Memory is measured in a child process (peak RSS under a 2 GB address-space cap), because
// libxml's memory lies outside PHP's memory_limit.

const R8_HEADER = '<row r="1"><c r="A1" t="inlineStr"><is><t>SKU</t></is></c><c r="B1" t="inlineStr"><is><t>Name</t></is></c></row>';

const R8_ROWS = '<row r="2"><c r="A2" t="inlineStr"><is><t>1</t></is></c><c r="B2" t="inlineStr"><is><t>Apron</t></is></c></row>';

const R8_NS = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';

afterEach(function () {
    foreach (['maxImportBytes' => 'DEFAULT_MAX_IMPORT_BYTES', 'maxNodes' => 'DEFAULT_MAX_NODES', 'maxCellElements' => 'DEFAULT_MAX_CELL_ELEMENTS', 'maxSeconds' => 'DEFAULT_MAX_SECONDS', 'maxSecondsPerValue' => 'DEFAULT_MAX_SECONDS_PER_VALUE'] as $property => $default) {
        if (property_exists(SpreadsheetReader::class, $property) && defined(SpreadsheetReader::class . '::' . $default)) {
            SpreadsheetReader::${$property} = constant(SpreadsheetReader::class . '::' . $default);
        }
    }
});

function r8Refusal(Closure $read): string
{
    try {
        $read();
    } catch (UnreadableSpreadsheet $exception) {
        return $exception->getMessage();
    }

    return 'read without a refusal';
}

function r8Rows(string $path, ?string $sheet = null): int
{
    return iterator_count(SpreadsheetReader::open($path, basename($path))->table($sheet)->rows());
}

/**
 * An XLSX package whose sheet part is written from pieces (encoded as given), never one string.
 *
 * @param  iterable<int, string>  $pieces
 */
function r8Xlsx(iterable $pieces): string
{
    return zipWithLargePart(xlsxWithSheet(''), 'xl/worksheets/sheet1.xml', $pieces);
}

function r8OdsWith(string $content): string
{
    return zipWith(['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet', 'content.xml' => $content], '.ods');
}

function r8OdsBody(string $rows): string
{
    return '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet><table:table table:name="Data">'
        . $rows . '</table:table></office:spreadsheet></office:body></office:document-content>';
}

function r8OdsRow(string ...$values): string
{
    return '<table:table-row>' . implode('', array_map(fn (string $v): string => '<table:table-cell office:value-type="string"><text:p>' . $v . '</text:p></table:table-cell>', $values)) . '</table:table-row>';
}

// 1. A part in UTF-16 hid its comments and processing instructions from the byte count, and
//    libxml kept them all (9.4 MB upload: 1.39 GB).

it('refuses a UTF-16 part before libxml keeps the markup it hides from the byte count', function (string $kind) {
    $pis = str_repeat(mb_convert_encoding('<?a?>', 'UTF-16LE', 'UTF-8'), 1000);

    $path = $kind === 'xlsx'
        ? r8Xlsx((function () use ($pis) {
            yield "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><worksheet ' . R8_NS . '><sheetData>' . R8_HEADER, 'UTF-16LE', 'UTF-8');

            for ($i = 0; $i < 900; $i++) {
                yield $pis;
            }

            yield mb_convert_encoding(R8_ROWS . '</sheetData></worksheet>', 'UTF-16LE', 'UTF-8');
        })())
        : zipWithLargePart(['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet'], 'content.xml', (function () use ($pis) {
            yield "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>' . substr(r8OdsBody(r8OdsRow('SKU', 'Name')), 0, -strlen('</table:table></office:spreadsheet></office:body></office:document-content>')), 'UTF-16LE', 'UTF-8');

            for ($i = 0; $i < 900; $i++) {
                yield $pis;
            }

            yield mb_convert_encoding(r8OdsRow('1', 'Apron') . '</table:table></office:spreadsheet></office:body></office:document-content>', 'UTF-16LE', 'UTF-8');
        })(), '.ods');

    $started = microtime(true);
    [$code, $output, $rss] = readInChildProcess($path, '128M', 'utf16.' . $kind);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('import.errors.unreadable')
        ->and($rss)->toBeLessThan(80 * 1024)
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
})->with(['xlsx', 'ods']);

it('reads only UTF-8 parts: other encodings are refused, never parsed', function (string $part) {
    $entries = xlsxWithSheet(R8_HEADER . R8_ROWS);
    $entries['xl/worksheets/sheet1.xml'] = $part;

    expect(r8Refusal(fn () => r8Rows(zipWith($entries))))->toContain('could not be read');
})->with([
    'UTF-16 BE mark' => "\xFE\xFF" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><worksheet ' . R8_NS . '><sheetData>' . R8_HEADER . R8_ROWS . '</sheetData></worksheet>', 'UTF-16BE', 'UTF-8'),
    'UTF-16 without a mark' => mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><worksheet ' . R8_NS . '><sheetData>' . R8_HEADER . R8_ROWS . '</sheetData></worksheet>', 'UTF-16LE', 'UTF-8'),
    'UTF-32 LE mark' => "\xFF\xFE\x00\x00" . mb_convert_encoding('<worksheet ' . R8_NS . '><sheetData>' . R8_HEADER . R8_ROWS . '</sheetData></worksheet>', 'UTF-32LE', 'UTF-8'),
    'a NUL byte' => '<?xml version="1.0"?><worksheet ' . R8_NS . '><sheetData>' . R8_HEADER . "\0" . R8_ROWS . '</sheetData></worksheet>',
    'ISO-8859-1 declared' => '<?xml version="1.0" encoding="ISO-8859-1"?><worksheet ' . R8_NS . '><sheetData>' . R8_HEADER . R8_ROWS . '</sheetData></worksheet>',
    'UTF-7 declared' => '<?xml version="1.0" encoding="UTF-7"?><worksheet ' . R8_NS . '><sheetData>' . R8_HEADER . R8_ROWS . '</sheetData></worksheet>',
    'EBCDIC (no "<" first)' => "\x4C\x6F\xA7\x94" . '<worksheet ' . R8_NS . '><sheetData>' . R8_HEADER . R8_ROWS . '</sheetData></worksheet>',
]);

it('still reads UTF-8 parts with a byte order mark, a lower-case declaration and binary parts beside them', function () {
    $entries = xlsxWithSheet(R8_HEADER . R8_ROWS);
    $entries['xl/worksheets/sheet1.xml'] = "\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"yes\"?>\r\n<worksheet " . R8_NS . '><sheetData>' . R8_HEADER . R8_ROWS . '</sheetData></worksheet>';
    $entries['xl/media/image1.png'] = "\x89PNG\r\n\x1A\n\0\0\0\rIHDR" . random_bytes(2000);

    expect(r8Rows(zipWith($entries)))->toBe(1);
});

// 2. An internal DTD subset had no limit: 300,000 unused entities cost a minute of CPU, parsed
//    again on every open, before the first node any budget could count.

it('refuses a document type declaration in any part it reads, before libxml parses it', function (string $where) {
    $dtd = '<!DOCTYPE x [' . implode('', array_map(fn (int $i): string => '<!ENTITY e' . $i . ' "v">', range(1, 50000))) . ']>';
    $entries = xlsxWithSheet(R8_HEADER . R8_ROWS);

    if ($where === 'ods') {
        $path = r8OdsWith('<?xml version="1.0"?>' . $dtd . r8OdsBody(r8OdsRow('SKU', 'Name') . r8OdsRow('1', 'Apron')));
    } else {
        $part = $where === 'sheet' ? 'xl/worksheets/sheet1.xml' : 'xl/workbook.xml';
        $entries[$part] = (string) preg_replace('/^(<\?xml[^>]*\?>)/', '$1' . $dtd, $entries[$part]);
        $path = zipWith($entries);
    }

    $started = microtime(true);

    expect(r8Refusal(fn () => r8Rows($path)))->toContain('could not be read')
        ->and(microtime(true) - $started)->toBeLessThan(1.0);
})->with(['sheet', 'workbook', 'ods']);

it('sees a document type declaration that straddles two reads of the part', function (int $padding) {
    $entries = xlsxWithSheet(R8_HEADER . R8_ROWS);
    $entries['xl/worksheets/sheet1.xml'] = '<?xml version="1.0"?>' . str_repeat(' ', $padding) . '<!DOCTYPE x []>' . substr($entries['xl/worksheets/sheet1.xml'], strlen('<?xml version="1.0"?>'));

    expect(r8Refusal(fn () => r8Rows(zipWith($entries))))->toContain('could not be read');
})->with([8170, 8180, 8185, 8188, 8190, 8192, 1048570]);

// 3. 24 nodes per value let junk next to real values run to the 32M cap (7 MB: 18 s of CPU).

it('allows about twice the nodes a real value costs, and a fixed time for work nodes cannot weigh', function () {
    $budget = new Budget(maxNodes: 48000000, maxMarkup: 10, maxRows: 10, maxCells: 10, rowLimit: 10);
    $budget->value();

    expect(Budget::NODES_PER_VALUE)->toBeLessThanOrEqual(12)
        ->and($budget->allowance)->toBe(Budget::NODE_BASE + Budget::NODES_PER_VALUE)
        ->and(SpreadsheetReader::DEFAULT_MAX_SECONDS)->toBeLessThanOrEqual(10.0);
});

it('refuses junk next to every value long before the node cap', function () {
    $path = r8Xlsx((function () {
        mt_srand(8);
        yield '<?xml version="1.0"?><worksheet ' . R8_NS . '><sheetData><row r="1">' . implode('', array_map(fn (int $i): string => '<c t="inlineStr"><is><t>H' . $i . '</t></is></c>', range(0, 25))) . '</row>';
        $junk = str_repeat('<x/>', 20);

        for ($r = 2; $r <= 20001; $r++) {
            $row = '<row r="' . $r . '">';

            for ($i = 0; $i < 26; $i++) {
                $row .= '<c><v>' . mt_rand(0, 999999) . '</v>' . $junk . '</c>';
            }

            yield $row . '</row>';
        }

        yield '</sheetData></worksheet>';
    })());

    $started = microtime(true);
    [$code, $output, $rss] = readInChildProcess($path);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('too_complex')
        ->and($rss)->toBeLessThan(80 * 1024)
        ->and(microtime(true) - $started)->toBeLessThan(10.0);
});

it('stops a reader that runs past its time, whatever it spends it on', function () {
    SpreadsheetReader::$maxSeconds = 0.3;
    SpreadsheetReader::$maxSecondsPerValue = 0.0;
    $path = r8Xlsx((function () {
        yield '<?xml version="1.0"?><worksheet ' . R8_NS . '><sheetData>' . R8_HEADER . '<row r="2"><c r="A2"><v>1</v></c>';

        // 2 million empty elements: 8 MB, under the size where the packing ratio is checked.
        for ($i = 0; $i < 20; $i++) {
            yield str_repeat('<x/>', 100000);
        }

        yield '</row></sheetData></worksheet>';
    })());

    $started = microtime(true);

    expect(r8Refusal(fn () => r8Rows($path)))->toContain('XML markup')
        ->and(microtime(true) - $started)->toBeLessThan(1.5);
});

// 5. Legit workbooks were refused: late shared strings, styled empty cells, the design point.

it('reads a sheet that uses shared strings far down a long list', function () {
    $entries = xlsxWithSheet('', '');
    $entries['xl/workbook.xml'] = str_replace('<sheet name="Data" sheetId="1" r:id="rId1"/>', '<sheet name="Words" sheetId="1" r:id="rId1"/><sheet name="Data" sheetId="2" r:id="rId3"/>', $entries['xl/workbook.xml']);
    $entries['xl/_rels/workbook.xml.rels'] = str_replace('</Relationships>', '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/></Relationships>', $entries['xl/_rels/workbook.xml.rels']);
    $entries['xl/worksheets/sheet1.xml'] = '<?xml version="1.0"?><worksheet ' . R8_NS . '><sheetData><row r="1"><c t="s"><v>0</v></c></row></sheetData></worksheet>';
    $rows = '<row r="1"><c t="s"><v>850000</v></c><c t="s"><v>850001</v></c></row>';

    for ($r = 2; $r <= 21; $r++) {
        $rows .= '<row r="' . $r . '"><c><v>' . $r . '</v></c><c t="s"><v>' . (850000 + $r) . '</v></c></row>';
    }

    $entries['xl/worksheets/sheet2.xml'] = '<?xml version="1.0"?><worksheet ' . R8_NS . '><sheetData>' . $rows . '</sheetData></worksheet>';
    $path = zipWithLargePart($entries, 'xl/sharedStrings.xml', (function () {
        yield '<?xml version="1.0"?><sst ' . R8_NS . ' count="850022" uniqueCount="850022">';

        for ($block = 0; $block < 850; $block++) {
            $items = '';

            for ($i = $block * 1000; $i < ($block + 1) * 1000; $i++) {
                $items .= '<si><t>w' . dechex($i) . '</t></si>';
            }

            yield $items;
        }

        yield '<si><t>SKU</t></si><si><t>Name</t></si>';

        for ($i = 2; $i <= 21; $i++) {
            yield '<si><t>item ' . $i . '</t></si>';
        }

        yield '</sst>';
    })());

    expect(r8Rows($path, 'Data'))->toBe(20);
});

it('counts an empty styled cell as half a cell', function () {
    // 12 rows of 2 values and 98 empty cells, read twice (preview and rows): 2,404 cells, but 2,456 halves of the 3,000 allowed.
    SpreadsheetReader::$maxCellElements = 1500;
    $empty = str_repeat('<c s="1"/>', 98);
    $rows = R8_HEADER;

    for ($r = 2; $r <= 13; $r++) {
        $rows .= '<row r="' . $r . '"><c r="A' . $r . '"><v>' . $r . '</v></c><c r="B' . $r . '" t="inlineStr"><is><t>n</t></is></c>' . $empty . '</row>';
    }

    expect(r8Rows(zipWith(xlsxWithSheet($rows))))->toBe(12);
});

it('gives empty cells nodes only next to real values, so a flood of them runs out at the base', function () {
    $budget = new Budget(maxNodes: 48000000, maxMarkup: 10, maxRows: 10, maxCells: 1000, rowLimit: 10);

    for ($i = 0; $i < 100; $i++) {
        $budget->emptyCell();
    }

    expect($budget->allowance)->toBe(Budget::NODE_BASE);

    $budget->value();

    expect($budget->allowance)->toBe(Budget::NODE_BASE + 3 * Budget::NODES_PER_VALUE);
});

it('leaves headroom over the stated design point of 50,000 rows of 80 columns', function () {
    expect(SpreadsheetReader::DEFAULT_MAX_CELL_ELEMENTS)->toBeGreaterThanOrEqual((int) (1.5 * 50000 * 80))
        ->and(SpreadsheetReader::DEFAULT_MAX_NODES)->toBeGreaterThanOrEqual((int) (1.5 * 28000000));
});

// 6. The import's byte budget counted raw text, not what is stored (3.8 KB upload: 205 MB of JSON).

it('counts the data budget as the JSON a row is stored as', function () {
    SpreadsheetReader::$maxImportBytes = 25000;
    $backslashes = "sku,name\n";
    $slashes = "sku,name\n";

    for ($r = 1; $r <= 100; $r++) {
        $backslashes .= $r . ',' . str_repeat('\\', 150) . "\n";
        $slashes .= $r . ',' . str_repeat('/', 150) . "\n";
    }

    expect(r8Refusal(fn () => r8Rows(tempFileWith($backslashes))))->toContain('MB of data')
        ->and(r8Rows(tempFileWith($slashes)))->toBe(100);
});

<?php

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use PNScripts\SheafgateLite\Reader\ReaderProcess;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

// Option B (W14-SG-READER-DECISION): XLSX and ODS are read in a child process with a memory
// cap and a wall-clock kill; the test process (the parent) never parses spreadsheet XML.

afterEach(function () {
    SpreadsheetReader::$workerSeconds = SpreadsheetReader::DEFAULT_WORKER_SECONDS;
    SpreadsheetReader::$workerMemoryMb = SpreadsheetReader::DEFAULT_WORKER_MEMORY_MB;
    SpreadsheetReader::$workerAddressSpaceMb = SpreadsheetReader::DEFAULT_WORKER_ADDRESS_SPACE_MB;
    SpreadsheetReader::$phpBinary = null;
    SpreadsheetReader::$maxSeconds = SpreadsheetReader::DEFAULT_MAX_SECONDS;
    SpreadsheetReader::$maxSecondsPerValue = SpreadsheetReader::DEFAULT_MAX_SECONDS_PER_VALUE;
});

/**
 * Whether a process is gone: not running and not left as a zombie (no /proc entry at all).
 */
function processGone(int $pid): bool
{
    for ($i = 0; $i < 100 && is_dir("/proc/{$pid}"); $i++) {
        usleep(10000);
    }

    return ! is_dir("/proc/{$pid}");
}

/**
 * An XLSX whose first data cell tag carries $attributes empty attributes: libxml checks
 * them for duplicates pairwise, so one tag (one node) costs seconds to minutes of CPU.
 */
function attributeFlood(int $attributes, string $where = 'cell'): string
{
    // Three-character names (7 bytes an attribute) keep the tag under the 512 KB stretch
    // without "<" that the archive check refuses: libxml gets to parse it.
    $first = array_merge(range('a', 'z'), range('A', 'Z'));
    $next = array_merge($first, range('0', '9'));
    $flood = '';

    for ($i = 0; $i < $attributes; $i++) {
        $flood .= ' ' . $first[intdiv($i, 3844) % 52] . $next[intdiv($i, 62) % 62] . $next[$i % 62] . '=""';
    }

    $sheet = '<row r="1"><c r="A1" t="inlineStr"><is><t>sku</t></is></c></row>'
        . '<row r="2"><c r="A2" t="inlineStr"' . ($where === 'cell' ? $flood : '') . '><is><t>1</t></is></c></row>';
    $entries = xlsxWithSheet($sheet);

    if ($where === 'workbook') {
        $entries['xl/workbook.xml'] = str_replace('<sheets>', '<fileVersion' . $flood . '/><sheets>', $entries['xl/workbook.xml']);
    }

    return zipWith($entries);
}

/**
 * A copy of a fixture under a new name: nothing cached for it yet, so the child really starts.
 */
function freshCopy(string $name): string
{
    $path = tempnam(sys_get_temp_dir(), 'sheafgate-fresh-') . '.' . pathinfo($name, PATHINFO_EXTENSION);
    copy(fixturePath($name), $path);

    return $path;
}

function readAll(SpreadsheetReader $reader): int
{
    $rows = 0;

    foreach ($reader->table()->rows() as $row) {
        $rows++;
    }

    return $rows;
}

it('reads XLSX and ODS in a child process that ends with the reader', function (string $file) {
    $reader = SpreadsheetReader::open(freshCopy($file), $file);

    expect(readAll($reader))->toBe(3);

    $pid = $reader->workerPid();

    expect($pid)->toBeInt()->not->toBe(getmypid());

    $reader->close();

    expect(processGone((int) $pid))->toBeTrue();
})->with(['catalogue-libreoffice.xlsx', 'catalogue-libreoffice.ods']);

it('keeps one child for every pass of one reader (its budget spans them all)', function () {
    $reader = SpreadsheetReader::open(freshCopy('catalogue-libreoffice.xlsx'), 'c.xlsx');
    $reader->preview($reader->firstSheetWithData(), 1);
    $pid = $reader->workerPid();
    readAll($reader);

    expect($reader->workerPid())->toBe($pid);
});

it('limits the child to its memory before it reads the file', function () {
    $reader = SpreadsheetReader::open(freshCopy('catalogue-libreoffice.xlsx'), 'c.xlsx');
    $reader->sheets();
    $pid = (int) $reader->workerPid();
    $limits = (string) file_get_contents("/proc/{$pid}/limits");

    expect(preg_match('/Max address space\s+(\d+)/', $limits, $m))->toBe(1)
        ->and((int) $m[1])->toBe(SpreadsheetReader::DEFAULT_WORKER_ADDRESS_SPACE_MB * 1024 * 1024)
        ->and(preg_match('/Max cpu time\s+(\d+)/', $limits, $c))->toBe(1)
        ->and((int) $c[1])->toBeLessThan(120);
})->skip(! function_exists('posix_setrlimit') || ! is_dir('/proc/self'), 'needs posix_setrlimit and /proc');

it('kills the child past the wall clock when one start tag carries a flood of attributes', function (string $where) {
    SpreadsheetReader::$workerSeconds = 1.0;
    $path = attributeFlood(50000, $where);
    $started = microtime(true);
    $pid = null;

    try {
        $reader = SpreadsheetReader::open($path, 'flood.xlsx');
        readAll($reader);
        $this->fail('The flood was read.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->reason)->toBe('too_complex');
    }

    // libxml alone needs about 12 s for this tag; the child is stopped after the wall clock.
    expect(microtime(true) - $started)->toBeLessThan(6.0);
})->with(['cell', 'workbook']);

it('keeps a killed child dead: every later look at the file gives the same clear error, and no zombie is left', function () {
    SpreadsheetReader::$workerSeconds = 0.5;
    $reader = SpreadsheetReader::open(attributeFlood(50000), 'flood.xlsx');
    $reader->sheets();
    $pid = (int) $reader->workerPid();

    expect(fn () => $reader->preview())->toThrow(UnreadableSpreadsheet::class)
        ->and(processGone($pid))->toBeTrue()
        ->and(fn () => $reader->table())->toThrow(UnreadableSpreadsheet::class);
});

it('gives the wall clock more time for every value the child hands back', function () {
    $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>sku</t></is></c><c r="B1" t="inlineStr"><is><t>name</t></is></c></row>';

    for ($r = 2; $r <= 30001; $r++) {
        $rows .= "<row r=\"{$r}\"><c r=\"A{$r}\"><v>{$r}</v></c><c r=\"B{$r}\" t=\"inlineStr\"><is><t>item {$r}</t></is></c></row>";
    }

    $path = zipWith(xlsxWithSheet($rows));

    // A base clock of 0.25 s is enough to start and preview, not to read 60,000 values:
    // without time per value the child is stopped; with 0.1 ms per value the sheet is read.
    SpreadsheetReader::$workerSeconds = 0.25;
    SpreadsheetReader::$maxSecondsPerValue = 0.0;

    try {
        readAll(SpreadsheetReader::open($path, 'v.xlsx'));
        $this->fail('60,000 values were read within 0.25 s.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->reason)->toBe('too_complex');
    }

    SpreadsheetReader::$maxSecondsPerValue = 0.0001;

    expect(readAll(SpreadsheetReader::open($path, 'v.xlsx')))->toBe(30000);
});

it('ends with a clear error when the child runs out of memory', function () {
    // 200,000 shared strings of 120 characters (24 MB of text) and a cell pointing at the
    // last one: the child must load them all, which a 24 MB memory_limit cannot hold.
    // Random text, so the archive's ratio check lets the part through to the child; written
    // in pieces (this test process runs at 128M too).
    $pieces = (function (): Generator {
        yield '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        for ($i = 0; $i < 200; $i++) {
            $chunk = '';

            for ($j = 0; $j < 1000; $j++) {
                $chunk .= '<si><t>' . bin2hex(random_bytes(60)) . '</t></si>';
            }

            yield $chunk;
        }

        yield '</sst>';
    })();
    $entries = xlsxWithSheet('<row r="1"><c r="A1" t="s"><v>199999</v></c></row><row r="2"><c r="A2"><v>1</v></c></row>', '');
    $path = zipWithLargePart($entries, 'xl/sharedStrings.xml', $pieces);
    SpreadsheetReader::$workerMemoryMb = 24;

    try {
        readAll(SpreadsheetReader::open($path, 'm.xlsx'));
        $this->fail('The strings fit.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->reason)->toBeIn(['reader_failed', 'too_large']);
    }
});

it('stops the child with the reader that stops early: a preview never reads the whole sheet', function () {
    $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>n</t></is></c></row>';

    for ($r = 2; $r <= 40001; $r++) {
        $rows .= "<row r=\"{$r}\"><c r=\"A{$r}\"><v>{$r}</v></c></row>";
    }

    $reader = SpreadsheetReader::open(zipWith(xlsxWithSheet($rows)), 'big.xlsx');
    $started = microtime(true);
    $preview = $reader->preview(null, 30);

    expect(count($preview))->toBe(30)
        ->and(microtime(true) - $started)->toBeLessThan(2.0);

    // The same child then reads the sheet to the end.
    expect(readAll($reader))->toBe(40000);
});

it('refuses XLSX and ODS with a clear message where no child process can start, and still reads CSV', function () {
    SpreadsheetReader::$phpBinary = '/nonexistent/php';

    expect(ReaderProcess::available())->toBeFalse()
        ->and(fn () => SpreadsheetReader::open(freshCopy('catalogue-libreoffice.xlsx'), 'c.xlsx'))->toThrow(UnreadableSpreadsheet::class, 'cannot read XLSX or ODS files safely')
        ->and(fn () => SpreadsheetReader::open(freshCopy('catalogue-libreoffice.ods'), 'c.ods'))->toThrow(UnreadableSpreadsheet::class, 'cannot read XLSX or ODS files safely')
        ->and(readAll(SpreadsheetReader::open(tempFileWith("sku,name\n1,A\n2,B\n"), 'c.csv')))->toBe(2);
});

it('refuses XLSX and still reads CSV on a host with proc_open disabled', function () {
    $script = tempnam(sys_get_temp_dir(), 'sheafgate-noproc-') . '.php';
    $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
    $xlsx = var_export(fixturePath('catalogue-libreoffice.xlsx'), true);
    $csv = var_export(tempFileWith("sku,name\n1,A\n"), true);
    $namespace = class_exists(\PNScripts\Sheafgate\Reader\SpreadsheetReader::class) ? 'PNScripts\\Sheafgate' : 'PNScripts\\SheafgateLite';
    file_put_contents($script, <<<PHP
<?php
function __(\$key, \$replace = []) { return \$key; }
require {$autoload};
\$reader = '\\{$namespace}\\Reader\\SpreadsheetReader';
try { \$reader::open({$xlsx}, 'c.xlsx'); echo "xlsx read\\n"; } catch (Throwable \$e) { echo 'xlsx: ', \$e->reason ?? get_class(\$e), "\\n"; }
\$rows = 0; foreach (\$reader::open({$csv}, 'c.csv')->table()->rows() as \$row) { \$rows++; }
echo "csv rows: \$rows\\n";
PHP);
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=proc_open ' . escapeshellarg($script) . ' 2>&1', $output, $code);
    @unlink($script);

    expect(implode("\n", $output))->toContain('xlsx: reader_unavailable')->toContain('csv rows: 1')
        ->and($code)->toBe(0);
});

it('names a child that is not PHP as unavailable, not as a broken file', function () {
    SpreadsheetReader::$phpBinary = '/bin/true';

    try {
        SpreadsheetReader::open(freshCopy('catalogue-libreoffice.xlsx'), 'c.xlsx')->sheets();
        $this->fail('Read without a PHP child.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->reason)->toBe('reader_unavailable');
    }
});

it('passes refusals from the child on with their message and values', function () {
    SpreadsheetReader::$maxCellElements = 5;

    try {
        $rows = '<row r="1"><c r="A1" t="inlineStr"><is><t>n</t></is></c></row>';

        for ($r = 2; $r <= 20; $r++) {
            $rows .= "<row r=\"{$r}\"><c r=\"A{$r}\"><v>{$r}</v></c></row>";
        }

        readAll(SpreadsheetReader::open(zipWith(xlsxWithSheet($rows)), 'r.xlsx'));
        $this->fail('All cells read.');
    } catch (UnreadableSpreadsheet $exception) {
        expect($exception->reason)->toBe('too_many_cells')
            ->and($exception->getMessage())->toContain('more than 5 cells');
    } finally {
        SpreadsheetReader::$maxCellElements = SpreadsheetReader::DEFAULT_MAX_CELL_ELEMENTS;
    }
});

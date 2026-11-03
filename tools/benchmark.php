<?php
// Measures what an upload costs: php tools/benchmark.php <file.xlsx|ods|csv>
// Prints the cold first request (archive check, sheet list, header detection, mapping
// examples), a second request (served from the per-upload cache) and one full read of all rows.
require __DIR__ . '/../vendor/autoload.php';

if (! function_exists('__')) {
    function __(string $key, array $replace = []): string
    {
        return $key;
    }
}

$reader = class_exists(\PNScripts\Sheafgate\Reader\SpreadsheetReader::class)
    ? \PNScripts\Sheafgate\Reader\SpreadsheetReader::class
    : \PNScripts\SheafgateLite\Reader\SpreadsheetReader::class;
$file = $argv[1] ?? exit("Usage: php tools/benchmark.php <file>\n");
$copy = tempnam(sys_get_temp_dir(), 'sheafgate-bench-');
copy($file, $copy); // a fresh path: nothing cached yet

$time = function (callable $step): float {
    $start = microtime(true);
    $step();

    return microtime(true) - $start;
};

$upload = fn () => (function () use ($reader, $copy, $file): void {
    $r = $reader::open($copy, basename($file));
    $sheet = $r->firstSheetWithData();
    $r->table($sheet)->sample(10);
})();

$first = $time($upload);
$second = $time($upload);
$rows = 0;
$read = $time(function () use ($reader, $copy, $file, &$rows): void {
    foreach ($reader::open($copy, basename($file))->table()->rows() as $row) {
        $rows++;
    }
});

printf("%s: %.1f MB, %d data rows\nupload, first request: %.2f s\nupload, next request:  %.2f s\nread all rows:         %.2f s\n", basename($file), filesize($file) / 1048576, $rows, $first, $second, $read);
@unlink($copy);

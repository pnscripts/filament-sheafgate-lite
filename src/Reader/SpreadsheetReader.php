<?php

namespace PNScripts\SheafgateLite\Reader;

use Closure;
use League\Csv\Reader as CsvReader;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use Throwable;
use ZipArchive;

/**
 * Reads XLSX workbooks (XlsxSheets) and ODS workbooks (OdsSheets), both streamed by this
 * package, and CSV/TSV files (any common encoding and delimiter) as tables of text.
 */
final class SpreadsheetReader
{
    public const PREVIEW_ROWS = 30;

    public const DEFAULT_MAX_ROWS = 50000;

    public const DEFAULT_MAX_ENTRY_BYTES = 256 * 1024 * 1024;

    /** Data rows read from one sheet before reading stops with a clear error. */
    public static int $maxRows = self::DEFAULT_MAX_ROWS;

    /** Largest unpacked size of one file inside an XLSX/ODS archive. */
    public static int $maxEntryBytes = self::DEFAULT_MAX_ENTRY_BYTES;

    /** Parts above 10 MB unpacked may not be more than this many times their packed size. */
    public const MAX_RATIO = 100;

    /** The most characters a spreadsheet cell can hold in Excel and LibreOffice. */
    public const MAX_CELL_LENGTH = 32767;

    /** How many times a workbook was read row by row (for tests and diagnostics). */
    public static int $parses = 0;

    /** More sheets than any real workbook lists. */
    public const MAX_SHEETS = 1000;

    /** The preview of a sheet stops after this many rows that hold only whitespace. */
    public const PREVIEW_BLANK_ROWS = 1000;

    public const DEFAULT_MAX_ROW_ELEMENTS = 2 * XlsxSheets::MAX_ROWS;

    /**
     * Row elements (empty ones included) and other sheet elements one reader may walk in
     * all its passes together: every sheet it previews and every full read. A sheet of a
     * million empty rows, or many sheets of them, ends here instead of being walked again.
     */
    public static int $maxRowElements = self::DEFAULT_MAX_ROW_ELEMENTS;

    public const DEFAULT_MAX_CELL_ELEMENTS = 4000000;

    /**
     * Cell elements, empty ones included, one reader may read in all its passes together:
     * a 50,000-row sheet of 80 columns. Empty cells cost almost nothing to store, so
     * without this a 3 MB upload of 60 million <c/> elements kept PHP busy for minutes.
     */
    public static int $maxCellElements = self::DEFAULT_MAX_CELL_ELEMENTS;

    /** The row limit of this reader (defaults to $maxRows). */
    public ?int $rowLimit = null;

    /** Elements walked so far by this reader (see $maxRowElements). */
    private int $rowElements = 0;

    /** Cell elements read so far by this reader (see $maxCellElements). */
    private int $cellElements = 0;

    /** The first sheet with data, once found (false: not looked for yet). */
    private string|null|false $firstSheet = false;

    private ?CsvSource $csv = null;

    /** @var list<string>|null */
    private ?array $sheetNames = null;

    private ?XlsxSheets $xlsxSheets = null;

    /** @var array<string, array<int, list<string>>> the first rows of each sheet, read once */
    private array $previews = [];

    private function __construct(
        public readonly string $path,
        public readonly string $format,
        private readonly ?string $csvEncoding,
        private readonly ?string $csvDelimiter,
    ) {}

    /**
     * @param  string|null  $fileName  the original name, used only when the content does not tell the format
     */
    public static function open(string $path, ?string $fileName = null, ?string $csvEncoding = null, ?string $csvDelimiter = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return new self($path, self::detectFormat($path, $fileName), $csvEncoding, $csvDelimiter);
    }

    public static function detectFormat(string $path, ?string $fileName = null): string
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 8);

        if ($handle !== false) {
            fclose($handle);
        }

        if (str_starts_with($magic, "PK\x03\x04")) {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw UnreadableSpreadsheet::because('unreadable');
            }

            try {
                self::assertSafeArchive($zip, $path);
            } catch (UnreadableSpreadsheet $exception) {
                $zip->close();

                throw $exception;
            }

            $mimetype = $zip->getFromName('mimetype');
            $isXlsx = $zip->locateName('xl/workbook.xml') !== false;
            $zip->close();

            return match (true) {
                $isXlsx => 'xlsx',
                is_string($mimetype) && str_starts_with(trim($mimetype), 'application/vnd.oasis.opendocument.spreadsheet') => 'ods',
                default => throw UnreadableSpreadsheet::because('not_spreadsheet'),
            };
        }

        if (str_starts_with($magic, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw UnreadableSpreadsheet::because('legacy_xls');
        }

        $extension = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'ods'], true)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return 'csv';
    }

    /**
     * Refuses archives that unpack to far more than they weigh (a "zip bomb") before any
     * entry is parsed: the sizes are read from the archive's directory.
     */
    /** More parts than any real workbook has; checked on the archive directory first. */
    public const MAX_ARCHIVE_ENTRIES = 5000;

    private static function assertSafeArchive(ZipArchive $zip, string $path): void
    {
        // An archive checked once is not unpacked again on every Livewire request.
        $marker = self::cachePath($path, 'safe');

        if ($marker !== null && is_file($marker)) {
            return;
        }

        $tooLarge = fn (): UnreadableSpreadsheet => UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(self::$maxEntryBytes / 1024 / 1024)]);

        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw $tooLarge();
        }

        $total = 0;
        $totalLimit = min(self::$maxEntryBytes, max(10 * 1024 * 1024, (int) filesize($path) * self::MAX_RATIO));

        // The sizes in the archive's directory can lie, so every entry is unpacked here and
        // the bytes it really produces are counted, stopping at the first limit it passes.
        // Every entry, whatever its name: a relationship may point the reader at any part
        // (a sheet named sheet1.bin, styles.dat), and entries are opened by index, so two
        // entries with the same name are both measured.
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $stat === false ? false : $zip->getNameIndex($index);

            if ($stat === false || $name === false || str_ends_with($name, '/')) {
                continue;
            }

            $packed = max(1, (int) $stat['comp_size']);
            $limit = min(self::$maxEntryBytes, max(10 * 1024 * 1024, $packed * self::MAX_RATIO));
            $stream = $zip->getStreamIndex($index);

            if ($stream === false) {
                throw UnreadableSpreadsheet::because('unreadable');
            }

            $size = 0;

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);

                    if ($chunk === false) {
                        throw UnreadableSpreadsheet::because('unreadable');
                    }

                    $size += strlen($chunk);
                    $total += strlen($chunk);

                    if ($size > $limit || $total > $totalLimit) {
                        throw $tooLarge();
                    }

                    if ($chunk === '') {
                        break;
                    }
                }
            } finally {
                fclose($stream);
            }
        }

        if ($marker !== null) {
            @touch($marker);
        }
    }

    /**
     * Where checked archives, sheet lists and previews of uploads are kept: the app's
     * storage/framework/cache/sheafgate (or the system temp folder outside Laravel).
     */
    public static function cacheDirectory(): string
    {
        $base = function_exists('storage_path') && function_exists('app') && app()->bound('path.storage')
            ? storage_path('framework/cache/sheafgate')
            : sys_get_temp_dir() . '/sheafgate-cache';

        if (! is_dir($base)) {
            @mkdir($base, 0700, true);
        }

        return $base;
    }

    /**
     * Removes everything cached for one upload (its previews hold uploaded values).
     */
    public static function forget(string $path): void
    {
        foreach (glob(self::cacheDirectory() . '/' . sha1($path) . '-*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Removes cached entries older than the given age (default 6 hours).
     */
    public static function sweep(int $seconds = 21600): int
    {
        $removed = 0;

        foreach (glob(self::cacheDirectory() . '/*') ?: [] as $file) {
            $changed = @filemtime($file);

            if ($changed !== false && $changed <= time() - $seconds && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * A cache file tied to this upload's path, size and change time.
     */
    private static function cachePath(string $path, string $kind, string $extra = ''): ?string
    {
        static $swept = false;

        $stat = @stat($path);

        if ($stat === false) {
            return null;
        }

        $folder = self::cacheDirectory();

        if (! is_dir($folder)) {
            return null;
        }

        if (! $swept) {
            $swept = true;
            self::sweep();
        }

        return $folder . '/' . sha1($path) . '-' . sha1($stat['size'] . '|' . $stat['mtime'] . '|' . $kind . '|' . $extra);
    }

    /**
     * Sheet names in workbook order (hidden sheets left out). A CSV file has one unnamed sheet.
     *
     * @return list<string>
     */
    public function sheets(): array
    {
        if ($this->sheetNames !== null) {
            return $this->sheetNames;
        }

        if ($this->format === 'csv') {
            return $this->sheetNames = [];
        }

        $cache = self::cachePath($this->path, 'sheets');
        $cached = $cache !== null && is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;

        if (is_array($cached)) {
            return $this->sheetNames = array_values(array_map(fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $cached));
        }

        $this->sheetNames = $this->readSheetNames();

        if ($cache !== null) {
            @file_put_contents($cache, (string) json_encode($this->sheetNames, JSON_UNESCAPED_UNICODE));
        }

        return $this->sheetNames;
    }

    /**
     * @return list<string>
     */
    private function readSheetNames(): array
    {
        if ($this->format === 'ods') {
            try {
                $names = $this->withXmlErrors(fn (): array => (new OdsSheets($this->path, $this->spendCell(...)))->names());

                if (count($names) > self::MAX_SHEETS) {
                    throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(self::$maxEntryBytes / 1024 / 1024)]);
                }

                return $names;
            } catch (UnreadableSpreadsheet $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw UnreadableSpreadsheet::because('unreadable', $exception);
            }
        }

        try {
            $sheets = $this->xlsx()->sheets();
        } catch (UnreadableSpreadsheet $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        }

        if (count($sheets) > self::MAX_SHEETS) {
            throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(self::$maxEntryBytes / 1024 / 1024)]);
        }

        $all = array_column($sheets, 'name');
        $visible = array_column(array_filter($sheets, fn (array $sheet): bool => $sheet['visible']), 'name');

        return $visible !== [] ? $visible : $all;
    }

    /**
     * The first sheet with at least one filled cell, or the first sheet.
     */
    public function firstSheetWithData(): ?string
    {
        if ($this->firstSheet !== false) {
            return $this->firstSheet;
        }

        $sheets = $this->sheets();

        // An ODS workbook keeps every sheet in one part: one pass finds the sheet, instead of
        // one pass per sheet that walks all the sheets before it again.
        if ($this->format === 'ods' && count($sheets) > 1) {
            $cache = self::cachePath($this->path, 'first');
            $found = $cache !== null && is_file($cache) ? (string) file_get_contents($cache) : null;

            if ($found === null) {
                $found = (string) $this->withXmlErrors(fn (): ?string => (new OdsSheets($this->path, $this->spendCell(...)))->firstFilled($this->spendRowElement(...), self::PREVIEW_BLANK_ROWS));

                if ($cache !== null) {
                    @file_put_contents($cache, $found);
                }
            }

            return $this->firstSheet = in_array($found, $sheets, true) ? $found : $sheets[0];
        }

        // Sheets that point at a part already looked at are not read again.
        $seen = [];

        foreach ($sheets as $sheet) {
            $key = $this->previewKey($sheet);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ($this->preview($sheet, 1) !== []) {
                return $this->firstSheet = $sheet;
            }
        }

        return $this->firstSheet = $sheets[0] ?? null;
    }

    /**
     * What a sheet's preview is kept under: its workbook part for XLSX, where several sheets
     * may point at the same part, otherwise its name.
     */
    private function previewKey(?string $sheet): string
    {
        $sheet = $this->resolveSheet($sheet);

        if ($this->format === 'xlsx' && $sheet !== null) {
            try {
                return 'part:' . $this->xlsx()->part($sheet);
            } catch (Throwable) {
                // An unknown sheet is refused when it is read.
            }
        }

        return (string) $sheet;
    }

    /**
     * Counts one walked element against this reader's budget.
     */
    private function spendRowElement(): void
    {
        if (++$this->rowElements > self::$maxRowElements) {
            throw UnreadableSpreadsheet::because('too_many_rows', replace: ['max' => number_format($this->rowLimit ?? self::$maxRows)]);
        }
    }

    /**
     * Counts one read cell element against this reader's budget.
     */
    private function spendCell(): void
    {
        if (++$this->cellElements > self::$maxCellElements) {
            throw UnreadableSpreadsheet::because('too_many_cells', replace: ['max' => number_format(self::$maxCellElements)]);
        }
    }

    /**
     * Runs a reader step with libxml errors collected, never shown, and any failure as a clear message.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    private function withXmlErrors(Closure $step): mixed
    {
        $previous = libxml_use_internal_errors(true);

        try {
            return $step();
        } catch (UnreadableSpreadsheet $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The first non-empty rows of a sheet as text, keyed by row number.
     *
     * @return array<int, list<string>>
     */
    public function preview(?string $sheet = null, int $limit = self::PREVIEW_ROWS): array
    {
        $key = $this->previewKey($sheet);

        if (! isset($this->previews[$key])) {
            $cache = self::cachePath($this->path, 'preview', $key . '|' . $this->csvEncoding . '|' . $this->csvDelimiter);
            $cached = $cache !== null && is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;

            if (is_array($cached)) {
                $this->previews[$key] = [];

                foreach ($cached as $number => $cells) {
                    if (is_array($cells)) {
                        $this->previews[$key][(int) $number] = array_values(array_map(fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '', $cells));
                    }
                }
            } else {
                $rows = [];
                $blank = 0;

                // Rows come without a width (the header is not known yet), so whitespace-only
                // far cells are never padded; a sheet of such rows ends the preview early.
                foreach ($this->cells($sheet) as $number => $cells) {
                    if (implode('', array_map('trim', $cells)) === '') {
                        if (++$blank > self::PREVIEW_BLANK_ROWS) {
                            break;
                        }

                        continue;
                    }

                    $rows[$number] = $cells;

                    if (count($rows) >= self::PREVIEW_ROWS) {
                        break;
                    }
                }

                $this->previews[$key] = $rows;

                if ($cache !== null) {
                    @file_put_contents($cache, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                }
            }
        }

        /** @var array<int, list<string>> $rows */
        $rows = $this->previews[$key];

        return array_slice($rows, 0, $limit, true);
    }

    /**
     * @param  int|null  $headerRow  1-based row number; detected when null
     * @param  array<string, list<string>>  $guesses  lower-case guesses per importer column, for header detection
     */
    public function table(?string $sheet = null, ?int $headerRow = null, array $guesses = []): Table
    {
        $sheet = $this->resolveSheet($sheet);
        $preview = $this->preview($sheet);

        if ($preview === []) {
            throw UnreadableSpreadsheet::because('empty');
        }

        $headerRow ??= HeaderDetector::detect($preview, $guesses);
        $headerCells = $preview[$headerRow] ?? $this->rowAt($sheet, (int) $headerRow);

        if ($headerCells === null || Headers::width($headerCells) === 0) {
            throw UnreadableSpreadsheet::because('no_header');
        }

        // Merged header cells show up as blanks between filled cells; without blanks there is nothing to look up.
        $width = Headers::width($headerCells);
        $hasBlanks = in_array('', array_map('trim', array_slice($headerCells, 0, $width)), true);
        $headers = Headers::normalize($headerCells, $hasBlanks ? $this->cachedHeaderMerges($sheet, (int) $headerRow) : []);
        $first = (int) $headerRow;
        $width = count($headers);

        return new Table(
            format: $this->format,
            sheet: $sheet,
            headerRow: $first,
            headers: $headers,
            cells: function () use ($sheet, $first, $width): \Generator {
                // Only the header's columns are kept: cells past them are never padded or stored.
                foreach ($this->cells($sheet, $width) as $number => $cells) {
                    if ($number > $first) {
                        yield $number => $cells;
                    }
                }
            },
            encoding: $this->csv()?->encoding,
            delimiter: $this->csv()?->delimiter,
            maxRows: $this->rowLimit ?? self::$maxRows,
            preview: array_filter($preview, fn (int $number): bool => $number > $first, ARRAY_FILTER_USE_KEY),
        );
    }

    public function csvEncoding(): ?string
    {
        return $this->csv()?->encoding;
    }

    public function csvDelimiter(): ?string
    {
        return $this->csv()?->delimiter;
    }

    /**
     * All rows of a sheet as text, keyed by 1-based row number (empty rows may be left out).
     * With a width, only the first $width columns of each row are kept; a row whose values
     * all lie past them comes as an empty list, so it still counts as a row.
     *
     * @return \Generator<int, list<string>>
     */
    public function cells(?string $sheet = null, ?int $width = null): \Generator
    {
        yield from $this->scan($sheet, $width);
    }

    /**
     * @return \Generator<int, list<string>>
     */
    private function scan(?string $sheet, ?int $width, ?int $only = null): \Generator
    {
        self::$parses++;
        $scanned = 0;
        $maxScanned = max(100000, ($this->rowLimit ?? self::$maxRows) * 4);
        $count = function () use (&$scanned, $maxScanned): void {
            if (++$scanned > $maxScanned) {
                throw UnreadableSpreadsheet::because('too_many_rows', replace: ['max' => number_format($this->rowLimit ?? self::$maxRows)]);
            }
        };
        $previous = libxml_use_internal_errors(true);

        try {
            foreach ($this->readCells($sheet, $width, $only, $count) as $number => $cells) {
                // Empty rows count too: a sheet can claim a row number in the millions.
                $count();

                // CSV included: a CSV cell is no longer than a spreadsheet cell either.
                foreach ($cells as $value) {
                    if (strlen($value) > self::MAX_CELL_LENGTH && mb_strlen($value) > self::MAX_CELL_LENGTH) {
                        throw UnreadableSpreadsheet::because('cell_too_long', replace: ['max' => number_format(self::MAX_CELL_LENGTH)]);
                    }
                }

                yield $number => $cells;
            }
        } catch (UnreadableSpreadsheet $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Broken XML, a damaged archive or a reader failure: a clear message, never an error page.
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  Closure(): void  $countBlank  counts a blank CSV record toward the scanned rows
     * @return \Generator<int, list<string>>
     */
    private function readCells(?string $sheet, ?int $width, ?int $only, Closure $countBlank): \Generator
    {
        $csv = $this->csv();

        if ($csv !== null) {
            $reader = CsvReader::from($csv->path);
            $reader->setDelimiter($csv->delimiter);
            // Blank lines are counted, never skipped silently: a file of millions of them
            // is refused at the scanned-row cap like any other sheet.
            $reader->includeEmptyRecords();

            foreach ($reader->getRecords() as $offset => $record) {
                if ($record === [] || $record === [null]) {
                    $countBlank();

                    continue;
                }

                if ($only !== null && $offset + 1 !== $only) {
                    if ($offset + 1 > $only) {
                        return;
                    }

                    continue;
                }

                // The same column limit as a spreadsheet: a line of a million fields is refused.
                if (count($record) > XlsxSheets::MAX_COLUMNS) {
                    throw UnreadableSpreadsheet::because('too_large_sheet');
                }

                $values = array_values($record);

                if ($width !== null && count($values) > $width) {
                    $values = array_slice($values, 0, $width);
                }

                yield $offset + 1 => array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $values);
            }

            return;
        }

        $sheet = $this->resolveSheet($sheet);

        if ($this->format === 'ods') {
            yield from (new OdsSheets($this->path, $this->spendCell(...)))->rows((string) $sheet, $width, $this->spendRowElement(...), $only);

            return;
        }

        yield from $this->xlsx()->rows((string) $sheet, $width, $this->spendRowElement(...), $only);
    }

    private function resolveSheet(?string $sheet): ?string
    {
        if ($this->format === 'csv') {
            return null;
        }

        if ($sheet !== null && in_array($sheet, $this->sheets(), true)) {
            return $sheet;
        }

        return $this->firstSheetWithData();
    }

    /**
     * @return list<string>|null
     */
    private function rowAt(?string $sheet, int $number): ?array
    {
        // Only that row is read: the rows above it are skipped unread, never padded.
        foreach ($this->scan($sheet, null, $number) as $index => $cells) {
            if ($index === $number) {
                return $cells;
            }

            if ($index > $number) {
                break;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function cachedHeaderMerges(?string $sheet, int $headerRow): array
    {
        $cache = self::cachePath($this->path, 'merges', $sheet . '|' . $headerRow);
        $cached = $cache !== null && is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;

        if (is_array($cached)) {
            return array_values(array_map(fn (mixed $pair): array => [is_array($pair) && is_numeric($pair[0] ?? null) ? (int) $pair[0] : 0, is_array($pair) && is_numeric($pair[1] ?? null) ? (int) $pair[1] : 0], $cached));
        }

        $merges = $this->headerMerges($sheet, $headerRow);

        if ($cache !== null) {
            @file_put_contents($cache, (string) json_encode($merges));
        }

        return $merges;
    }

    /**
     * Merged cells that span several columns of the header row.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function headerMerges(?string $sheet, int $headerRow): array
    {
        try {
            return match ($this->format) {
                'xlsx' => $this->xlsxMerges($sheet, $headerRow),
                'ods' => (new OdsSheets($this->path, $this->spendCell(...)))->merges((string) $sheet, $headerRow, $this->spendRowElement(...)),
                default => [],
            };
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function xlsxMerges(?string $sheet, int $headerRow): array
    {
        $merges = [];

        foreach ($this->xlsx()->merges((string) $sheet) as $range) {
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', strtoupper($range), $m)) {
                continue;
            }

            if ((int) $m[2] <= $headerRow && (int) $m[4] >= $headerRow && $m[1] !== $m[3]) {
                $merges[] = [Headers::columnIndex($m[1]), Headers::columnIndex($m[3])];
            }
        }

        return $merges;
    }

    private function csv(): ?CsvSource
    {
        if ($this->format !== 'csv') {
            return null;
        }

        return $this->csv ??= CsvSource::open($this->path, $this->csvEncoding, $this->csvDelimiter);
    }

    private function xlsx(): XlsxSheets
    {
        return $this->xlsxSheets ??= new XlsxSheets($this->path, $this->spendCell(...));
    }
}

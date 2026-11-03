<?php

namespace PNScripts\SheafgateLite\Reader;

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

    

    /** The row limit of this reader (defaults to $maxRows). */
    public ?int $rowLimit = null;

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
    private static function assertSafeArchive(ZipArchive $zip, string $path): void
    {
        // An archive checked once is not unpacked again on every Livewire request.
        $marker = self::cachePath($path, 'safe');

        if ($marker !== null && is_file($marker)) {
            return;
        }

        $total = 0;
        $tooLarge = fn (): UnreadableSpreadsheet => UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(self::$maxEntryBytes / 1024 / 1024)]);

        // The sizes in the archive's directory can lie, so every part is unpacked here and the
        // bytes it really produces are counted, stopping at the first limit it passes.
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $stat === false ? false : $zip->getNameIndex($index);

            if ($stat === false || $name === false || str_ends_with($name, '/')) {
                continue;
            }

            $packed = max(1, (int) $stat['comp_size']);
            $limit = min(self::$maxEntryBytes, max(10 * 1024 * 1024, $packed * self::MAX_RATIO));
            $stream = $zip->getStream($name);

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

                    if ($size > $limit || $total > self::$maxEntryBytes) {
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
     * A file in the system temp folder tied to this upload's path, size and change time.
     */
    private static function cachePath(string $path, string $kind, string $extra = ''): ?string
    {
        $stat = @stat($path);

        if ($stat === false) {
            return null;
        }

        $folder = sys_get_temp_dir() . '/sheafgate-cache';

        if (! is_dir($folder) && ! @mkdir($folder, 0700, true) && ! is_dir($folder)) {
            return null;
        }

        return $folder . '/' . sha1($path . '|' . $stat['size'] . '|' . $stat['mtime'] . '|' . $kind . '|' . $extra);
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
                return (new OdsSheets($this->path))->names();
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

        $all = array_column($sheets, 'name');
        $visible = array_column(array_filter($sheets, fn (array $sheet): bool => $sheet['visible']), 'name');

        return $visible !== [] ? $visible : $all;
    }

    /**
     * The first sheet with at least one filled cell, or the first sheet.
     */
    public function firstSheetWithData(): ?string
    {
        foreach ($this->sheets() as $sheet) {
            if ($this->preview($sheet, 1) !== []) {
                return $sheet;
            }
        }

        return $this->sheets()[0] ?? null;
    }

    /**
     * The first non-empty rows of a sheet as text, keyed by row number.
     *
     * @return array<int, list<string>>
     */
    public function preview(?string $sheet = null, int $limit = self::PREVIEW_ROWS): array
    {
        $key = (string) $this->resolveSheet($sheet);

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

                foreach ($this->cells($sheet) as $number => $cells) {
                    if (implode('', array_map('trim', $cells)) === '') {
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

        return new Table(
            format: $this->format,
            sheet: $sheet,
            headerRow: $first,
            headers: $headers,
            cells: function () use ($sheet, $first): \Generator {
                foreach ($this->cells($sheet) as $number => $cells) {
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
     *
     * @return \Generator<int, list<string>>
     */
    public function cells(?string $sheet = null): \Generator
    {
        self::$parses++;
        $scanned = 0;
        $maxScanned = max(100000, ($this->rowLimit ?? self::$maxRows) * 4);
        $previous = libxml_use_internal_errors(true);

        try {
            foreach ($this->readCells($sheet) as $number => $cells) {
                // Empty rows count too: a sheet can claim a row number in the millions.
                if (++$scanned > $maxScanned) {
                    throw UnreadableSpreadsheet::because('too_many_rows', replace: ['max' => number_format($this->rowLimit ?? self::$maxRows)]);
                }

                foreach ($this->format === 'csv' ? [] : $cells as $value) {
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
     * @return \Generator<int, list<string>>
     */
    private function readCells(?string $sheet): \Generator
    {
        $csv = $this->csv();

        if ($csv !== null) {
            $reader = CsvReader::from($csv->path);
            $reader->setDelimiter($csv->delimiter);

            foreach ($reader->getRecords() as $offset => $record) {
                yield $offset + 1 => array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : '', array_values($record));
            }

            return;
        }

        $sheet = $this->resolveSheet($sheet);

        if ($this->format === 'ods') {
            yield from (new OdsSheets($this->path))->rows((string) $sheet);

            return;
        }

        yield from $this->xlsx()->rows((string) $sheet);
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
        foreach ($this->cells($sheet) as $index => $cells) {
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
                'ods' => (new OdsSheets($this->path))->merges((string) $sheet, $headerRow),
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
        return $this->xlsxSheets ??= new XlsxSheets($this->path);
    }
}

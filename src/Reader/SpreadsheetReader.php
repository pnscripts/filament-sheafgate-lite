<?php

namespace PNScripts\SheafgateLite\Reader;

use League\Csv\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\SheetWithMergeCellsInterface;
use OpenSpout\Reader\SheetWithVisibilityInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use Throwable;
use ZipArchive;

/**
 * Reads XLSX workbooks (streamed with OpenSpout), ODS workbooks (streamed with our own
 * OdsSheets) and CSV/TSV files (any common encoding and delimiter) as tables of text.
 */
final class SpreadsheetReader
{
    public const PREVIEW_ROWS = 30;

    private ?CsvSource $csv = null;

    /** @var list<string>|null */
    private ?array $sheetNames = null;

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

        if ($this->format === 'ods') {
            try {
                return $this->sheetNames = (new OdsSheets($this->path))->names();
            } catch (UnreadableSpreadsheet $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw UnreadableSpreadsheet::because('unreadable', $exception);
            }
        }

        $visible = [];
        $all = [];

        $this->withReader(function (ReaderInterface $reader) use (&$visible, &$all): void {
            foreach ($reader->getSheetIterator() as $sheet) {
                $all[] = $sheet->getName();

                if (! ($sheet instanceof SheetWithVisibilityInterface) || $sheet->isVisible()) {
                    $visible[] = $sheet->getName();
                }
            }
        });

        return $this->sheetNames = ($visible !== [] ? $visible : $all);
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
        $rows = [];

        foreach ($this->cells($sheet) as $number => $cells) {
            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }

            $rows[$number] = $cells;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
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

        $headers = Headers::normalize($headerCells, $this->headerMerges($sheet, (int) $headerRow));
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
        if ($this->format === 'csv') {
            $reader = CsvReader::from($this->csv()->path);
            $reader->setDelimiter($this->csv()->delimiter);

            foreach ($reader->getRecords() as $offset => $record) {
                yield $offset + 1 => array_map(fn (mixed $value): string => (string) $value, array_values($record));
            }

            return;
        }

        $sheet = $this->resolveSheet($sheet);

        if ($this->format === 'ods') {
            yield from (new OdsSheets($this->path))->rows((string) $sheet);

            return;
        }

        $reader = $this->makeReader();

        try {
            $reader->open($this->path);
        } catch (Throwable $exception) {
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        }

        try {
            foreach ($reader->getSheetIterator() as $candidate) {
                if ($candidate->getName() !== $sheet) {
                    continue;
                }

                foreach ($candidate->getRowIterator() as $number => $row) {
                    yield (int) $number => array_map(CellValue::toText(...), array_values($row->getCells()));
                }

                break;
            }
        } finally {
            $reader->close();
        }
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

        $this->withReader(function (ReaderInterface $reader) use ($sheet, $headerRow, &$merges): void {
            /** @var SheetInterface<\OpenSpout\Reader\RowIteratorInterface> $candidate */
            foreach ($reader->getSheetIterator() as $candidate) {
                if ($candidate->getName() !== $sheet || ! $candidate instanceof SheetWithMergeCellsInterface) {
                    continue;
                }

                foreach ($candidate->getMergeCells() as $range) {
                    if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', strtoupper($range), $m)) {
                        continue;
                    }

                    if ((int) $m[2] <= $headerRow && (int) $m[4] >= $headerRow && $m[1] !== $m[3]) {
                        $merges[] = [Headers::columnIndex($m[1]), Headers::columnIndex($m[3])];
                    }
                }
            }
        }, loadMerges: true);

        return $merges;
    }

    private function csv(): ?CsvSource
    {
        if ($this->format !== 'csv') {
            return null;
        }

        return $this->csv ??= CsvSource::open($this->path, $this->csvEncoding, $this->csvDelimiter);
    }

    private function makeReader(bool $loadMerges = false): ReaderInterface
    {
        $options = new XlsxOptions;
        $options->SHOULD_FORMAT_DATES = false;
        // Empty rows are kept so the row numbers match the ones the spreadsheet shows.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

        if ($loadMerges && property_exists($options, 'SHOULD_LOAD_MERGE_CELLS')) {
            $options->SHOULD_LOAD_MERGE_CELLS = true;
        }

        return new XlsxReader($options);
    }

    /**
     * @param  callable(ReaderInterface): void  $callback
     */
    private function withReader(callable $callback, bool $loadMerges = false): void
    {
        $reader = $this->makeReader($loadMerges);

        try {
            $reader->open($this->path);
        } catch (Throwable $exception) {
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        }

        try {
            $callback($reader);
        } catch (UnreadableSpreadsheet $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        } finally {
            $reader->close();
        }
    }
}

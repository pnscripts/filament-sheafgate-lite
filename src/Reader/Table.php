<?php

namespace PNScripts\SheafgateLite\Reader;

use Closure;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use Generator;

/**
 * One sheet read as a table: the header names and the data rows below the header row.
 */
final class Table
{
    /**
     * @param  list<string>  $headers
     * @param  Closure(): iterable<int, list<string>>  $cells  data rows keyed by 1-based row number
     */
    public function __construct(
        public readonly string $format,
        public readonly ?string $sheet,
        public readonly int $headerRow,
        public readonly array $headers,
        private readonly Closure $cells,
        public readonly ?string $encoding = null,
        public readonly ?string $delimiter = null,
        public readonly ?int $maxRows = null,
    ) {}

    /**
     * Non-empty data rows as [header => text], keyed by their row number in the file.
     *
     * @return Generator<int, array<string, string>>
     */
    public function rows(bool $limited = true): Generator
    {
        $width = count($this->headers);

        if ($width === 0) {
            return;
        }

        $count = 0;

        foreach (($this->cells)() as $number => $cells) {
            $cells = array_slice(array_pad($cells, $width, ''), 0, $width);

            if (implode('', array_map('trim', $cells)) === '') {
                continue;
            }

            // Stop while streaming: a sheet of millions of rows is never read to the end.
            if ($limited && $this->maxRows !== null && ++$count > $this->maxRows) {
                throw UnreadableSpreadsheet::because('too_many_rows', replace: ['max' => number_format($this->maxRows)]);
            }

            yield $number => array_combine($this->headers, $cells);
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function sample(int $limit = 5): array
    {
        $sample = [];

        foreach ($this->rows(limited: false) as $number => $row) {
            $sample[$number] = $row;

            if (count($sample) >= $limit) {
                break;
            }
        }

        return $sample;
    }

    /**
     * Refuses a row with a formula without a result or an error cell: importing it would
     * write 0 or an empty value where the spreadsheet shows something else.
     *
     * @param  array<string, string>  $row
     */
    public function assertImportable(int $number, array $row): void
    {
        $column = 0;

        foreach ($row as $header => $value) {
            if (CellValue::isProblem($value)) {
                $error = CellValue::errorCode($value);
                $replace = ['cell' => Headers::letter($column) . $number, 'column' => $header];

                throw $error === null
                    ? UnreadableSpreadsheet::because('formula_cell', replace: $replace)
                    : UnreadableSpreadsheet::because('error_cell', replace: [...$replace, 'error' => $error]);
            }

            $column++;
        }
    }

    /**
     * The table as a UTF-8, comma-separated CSV stream with the header first: the input
     * Filament's own importer reads.
     *
     * @return resource
     */
    public function toCsvStream()
    {
        $stream = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');

        if ($stream === false) {
            throw new \RuntimeException('Could not open a temporary stream.');
        }

        fputcsv($stream, $this->headers, ',', '"', '');

        foreach ($this->rows() as $number => $row) {
            $this->assertImportable($number, $row);
            fputcsv($stream, array_values($row), ',', '"', '');
        }

        rewind($stream);

        return $stream;
    }
}

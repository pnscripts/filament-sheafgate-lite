<?php

namespace PNScripts\SheafgateLite\Reader;

use Closure;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use XMLReader;

/**
 * A small streaming reader for OpenDocument spreadsheets (content.xml).
 *
 * Written for this package because merged ("covered") cells must keep their column,
 * row numbers must match what the spreadsheet shows, and the millions of repeated
 * empty rows LibreOffice writes at the end of a sheet must cost nothing.
 *
 * Read node by node (XmlStream) against the reader's Budget: no cell is built as a DOM,
 * and repeated cells and rows count as often as they are expanded.
 */
final class OdsSheets
{
    private const TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    private const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    /** A repeated row with content (rare: identical rows) is expanded at most this often. */
    private const MAX_REPEATED_ROWS = 10000;

    /** The table rowStarts() stands in (useful when it walks every table). */
    private ?string $table = null;

    public function __construct(private readonly string $path, private readonly Budget $budget) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $xml = $this->open();
        $names = [];

        try {
            $moved = $xml->read() && XmlStream::count($xml, $this->budget);

            while ($moved) {
                if ($xml->nodeType === XMLReader::ELEMENT && $this->is($xml, self::TABLE, 'table')) {
                    $names[] = (string) $xml->getAttributeNs('name', self::TABLE);

                    if (count($names) > SpreadsheetReader::MAX_SHEETS) {
                        throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(SpreadsheetReader::$maxEntryBytes / 1024 / 1024)]);
                    }

                    $moved = XmlStream::skip($xml, $this->budget) && $xml->read() && XmlStream::count($xml, $this->budget);

                    continue;
                }

                $moved = $xml->read() && XmlStream::count($xml, $this->budget);
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }

        return $names;
    }

    /**
     * Rows of one sheet as text, keyed by 1-based row number; empty rows are left out.
     *
     * Rows are read cell by cell, never built whole: a row may hold at most
     * XlsxSheets::MAX_COLUMNS cell elements, and only the first $width columns are kept
     * (a row whose only values lie past them is yielded as an empty list, so it still
     * counts as a row). Without a width (the preview) the first
     * SpreadsheetReader::PREVIEW_COLUMNS columns are kept and whitespace-only cells after
     * the last filled one are dropped. Repeated cells are expanded only within the kept
     * columns, and every expanded cell and row is counted against the budget.
     *
     * $onFlag hears of rows that come from repeat attributes ("repeated") and rows with a
     * value past the kept columns ("wide"). With $only, just that row is read.
     *
     * @param  (Closure(int, string): void)|null  $onFlag
     * @return \Generator<int, list<string>>
     */
    public function rows(string $sheet, ?int $width = null, ?int $only = null, ?Closure $onFlag = null): \Generator
    {
        $xml = $this->open();

        try {
            foreach ($this->rowStarts($xml, $sheet) as $first => $repeat) {
                if ($only !== null) {
                    if ($first > $only) {
                        break;
                    }

                    // Rows above the wanted one are stepped over unread.
                    if ($first + $repeat - 1 < $only) {
                        continue;
                    }

                    [$cells, $filled, $beyond] = $this->streamRow($xml, $width);

                    if ($beyond && $onFlag !== null) {
                        $onFlag($only, 'wide');
                    }

                    if ($filled) {
                        yield $only => $cells;
                    }

                    break;
                }

                [$cells, $filled, $beyond, $repeatedCells] = $this->streamRow($xml, $width);

                if (! $filled) {
                    continue;
                }

                if ($first > XlsxSheets::MAX_ROWS) {
                    throw UnreadableSpreadsheet::because('too_large_sheet');
                }

                $copies = min($repeat, self::MAX_REPEATED_ROWS);

                for ($i = 0; $i < $copies; $i++) {
                    if ($i > 0) {
                        // A copy costs what a row of its own would.
                        $this->budget->row();
                        $this->budget->cells(max(1, count($cells)));
                    }

                    if ($onFlag !== null) {
                        if ($copies > 1 || $repeatedCells) {
                            $onFlag($first + $i, 'repeated');
                        }

                        if ($beyond) {
                            $onFlag($first + $i, 'wide');
                        }
                    }

                    yield $first + $i => $cells;
                }
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }
    }

    /**
     * The first table with a row holding a non-blank value, found in one pass over the
     * document (never one pass per table). A table whose first $blankRows rows hold only
     * whitespace counts as empty, as the preview does.
     */
    public function firstFilled(int $blankRows = PHP_INT_MAX): ?string
    {
        $xml = $this->open();
        $table = null;
        $blank = 0;

        try {
            foreach ($this->rowStarts($xml, null) as $repeat) {
                if ($this->table !== $table) {
                    $table = $this->table;
                    $blank = 0;
                }

                if ($blank > $blankRows) {
                    continue;
                }

                [$cells, $filled] = $this->streamRow($xml, null);

                if (! $filled) {
                    continue;
                }

                if (implode('', array_map('trim', $cells)) !== '') {
                    return $table;
                }

                $blank += min($repeat, self::MAX_REPEATED_ROWS);
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }

        return null;
    }

    /**
     * Cells spanning several columns in one row, as 0-based [first, last] columns.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function merges(string $sheet, int $rowNumber): array
    {
        $xml = $this->open();

        try {
            foreach ($this->rowStarts($xml, $sheet) as $first => $repeat) {
                if ($first > $rowNumber) {
                    break;
                }

                if ($first + $repeat - 1 < $rowNumber) {
                    continue;
                }

                $merges = [];
                $column = 0;
                $count = 0;
                $depth = $xml->depth;
                $moved = $xml->read() && XmlStream::count($xml, $this->budget);

                while ($moved && ! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
                    if ($xml->nodeType !== XMLReader::ELEMENT) {
                        $moved = $xml->read() && XmlStream::count($xml, $this->budget);

                        continue;
                    }

                    if ($this->isCell($xml)) {
                        if (++$count > XlsxSheets::MAX_COLUMNS) {
                            throw UnreadableSpreadsheet::because('too_large_sheet');
                        }

                        $this->budget->cells();
                        $span = (int) ($xml->getAttributeNs('number-columns-spanned', self::TABLE) ?: 1);

                        if ($xml->localName === 'table-cell' && $span > 1) {
                            $merges[] = [$column, $column + $span - 1];
                        }

                        $column += max(1, (int) ($xml->getAttributeNs('number-columns-repeated', self::TABLE) ?: 1));
                    }

                    $moved = XmlStream::skip($xml, $this->budget) && $xml->read() && XmlStream::count($xml, $this->budget);
                }

                return $merges;
            }
        } finally {
            $xml->close();
        }

        return [];
    }

    /**
     * Walks to the row elements of one sheet (or, without a name, of every sheet in turn)
     * and yields each one's first row number and repeat count while the reader stands on it.
     * The consumer reads the row's content (or not); the walk continues after the row either
     * way. Every element visited counts as a row element, every node as a node.
     *
     * @return \Generator<int, int>
     */
    private function rowStarts(XMLReader $xml, ?string $sheet): \Generator
    {
        $budget = $this->budget;
        $inSheet = false;
        $depth = 0;
        $number = 1;
        $this->table = null;
        $moved = $xml->read() && XmlStream::count($xml, $budget);

        while ($moved) {
            if ($xml->nodeType === XMLReader::END_ELEMENT && $inSheet && $xml->depth === $depth && $this->is($xml, self::TABLE, 'table')) {
                if ($sheet !== null) {
                    break;
                }

                $inSheet = false;
                $moved = $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            if ($xml->nodeType !== XMLReader::ELEMENT) {
                $moved = $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            $budget->row();

            if (! $inSheet && $this->is($xml, self::TABLE, 'table')) {
                $name = (string) $xml->getAttributeNs('name', self::TABLE);

                if (($sheet === null || $name === $sheet) && ! $xml->isEmptyElement) {
                    $inSheet = true;
                    $this->table = $name;
                    $depth = $xml->depth;
                    $number = 1;
                    $moved = $xml->read() && XmlStream::count($xml, $budget);
                } else {
                    $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);
                }

                continue;
            }

            if (! $inSheet || ! $this->is($xml, self::TABLE, 'table-row')) {
                $moved = $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            $repeat = max(1, (int) ($xml->getAttributeNs('number-rows-repeated', self::TABLE) ?? 1));
            $rowDepth = $xml->depth;
            $empty = $xml->isEmptyElement;

            if (! $empty) {
                yield $number => $repeat;
            }

            $number += $repeat;

            // Continue after the row: stepped over when the consumer did not read it, or
            // from its end tag when it did.
            if ($empty || $this->standsOnStart($xml, $rowDepth)) {
                $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            while (! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $rowDepth)) {
                if (! $xml->read() || ! XmlStream::count($xml, $budget)) {
                    return;
                }
            }

            $moved = $xml->read() && XmlStream::count($xml, $budget);
        }
    }

    /**
     * Whether the reader still stands on the start tag of the element at that depth.
     */
    private function standsOnStart(XMLReader $xml, int $depth): bool
    {
        return $xml->nodeType === XMLReader::ELEMENT && $xml->depth === $depth;
    }

    private function isCell(XMLReader $xml): bool
    {
        return $xml->namespaceURI === self::TABLE && ($xml->localName === 'table-cell' || $xml->localName === 'covered-table-cell');
    }

    /**
     * Reads the cells of the row the reader stands on and leaves it on the row's end tag.
     *
     * @return array{0: list<string>, 1: bool, 2: bool, 3: bool} the kept cells, whether the row
     *                                                           has any value, whether it has one past the kept columns, and whether a filled cell
     *                                                           was repeated
     */
    private function streamRow(XMLReader $xml, ?int $width): array
    {
        $budget = $this->budget;
        $depth = $xml->depth;
        $keep = $width ?? SpreadsheetReader::PREVIEW_COLUMNS;
        $trim = $width === null;
        /** @var array<int, string> $cells kept values by column, padded only at the end */
        $cells = [];
        $last = -1;
        $filled = false;
        $beyond = false;
        $repeated = false;
        $count = 0;
        $column = 0;
        $bytes = 0;
        $moved = $xml->read() && XmlStream::count($xml, $budget);

        while ($moved && ! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
            if ($xml->nodeType !== XMLReader::ELEMENT) {
                $moved = $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            if (! $this->isCell($xml)) {
                $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            // A row can never hold more cell elements than a sheet has columns.
            if (++$count > XlsxSheets::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            $budget->cells();
            $repeat = max(1, (int) ($xml->getAttributeNs('number-columns-repeated', self::TABLE) ?: 1));
            $first = $column;
            $column = min($column + $repeat, XlsxSheets::MAX_COLUMNS + 1);
            $outside = $first >= $keep;

            // Covered cells are empty; past the kept columns only whether there is a value matters.
            if ($xml->localName === 'covered-table-cell' || ($outside && $beyond)) {
                $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            // An empty cell without a value type or formula has no value: stepped over.
            if ($xml->isEmptyElement && $xml->getAttributeNs('value-type', self::OFFICE) === null && $xml->getAttributeNs('formula', self::TABLE) === null) {
                $moved = $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            $value = $this->cellValue($xml);
            $moved = $xml->read() && XmlStream::count($xml, $budget);

            if ($value === '') {
                continue;
            }

            $budget->value();

            if ($first >= XlsxSheets::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            $filled = true;

            // Past the kept columns a whitespace-only value is no reason to call the row wide.
            if ($outside) {
                $beyond = trim($value) !== '';

                continue;
            }

            // A repeated value fills its columns, but never past the kept ones.
            $end = min($column, $keep);

            if ($column > $keep && trim($value) !== '') {
                $beyond = true;
            }

            if ($repeat > 1) {
                $repeated = true;
                $budget->cells($end - $first - 1);
            }

            $bytes += strlen($value) * ($end - $first);

            if ($bytes > SpreadsheetReader::MAX_ROW_BYTES) {
                throw UnreadableSpreadsheet::because('row_too_large', replace: ['max' => (int) (SpreadsheetReader::MAX_ROW_BYTES / 1024 / 1024)]);
            }

            for ($i = $first; $i < $end; $i++) {
                $cells[$i] = $value;
            }

            // Without a width a whitespace-only far cell must not pad the row to it.
            if ($end - 1 > $last && (! $trim || trim($value) !== '')) {
                $last = $end - 1;
            }
        }

        $row = [];

        for ($i = 0; $i <= $last; $i++) {
            $row[] = $cells[$i] ?? '';
        }

        return [$row, $filled, $beyond, $repeated];
    }

    /**
     * The value of the cell the reader stands on, read node by node; leaves the reader on
     * the cell's end tag (or on the cell, when it is empty).
     */
    private function cellValue(XMLReader $xml): string
    {
        $type = (string) $xml->getAttributeNs('value-type', self::OFFICE);
        $hasFormula = $xml->getAttributeNs('formula', self::TABLE) !== null;
        $stringValue = $xml->getAttributeNs('string-value', self::OFFICE);
        $attributes = [
            'value' => (string) $xml->getAttributeNs('value', self::OFFICE),
            'boolean' => (string) $xml->getAttributeNs('boolean-value', self::OFFICE),
            'date' => (string) $xml->getAttributeNs('date-value', self::OFFICE),
            'time' => (string) $xml->getAttributeNs('time-value', self::OFFICE),
        ];
        $needsText = $hasFormula || ! in_array($type, ['float', 'percentage', 'currency', 'boolean', 'date', 'time', 'string'], true) || ($type === 'string' && $stringValue === null);
        $text = $this->cellText($xml, $needsText);

        if ($hasFormula) {
            $shown = $text;

            // LibreOffice saves a failed formula as text such as "#DIV/0!" or "Err:502".
            if ($type === '' || $type === 'void') {
                return $shown === '' ? CellValue::FORMULA : CellValue::ERROR . $shown;
            }

            if ($type === 'string' && preg_match('/^(#[A-Z\/0!?]+[!?A-Z0-9]*|Err:\d+)$/', trim($shown))) {
                return CellValue::ERROR . trim($shown);
            }
        }

        switch ($type) {
            case 'float':
            case 'percentage':
            case 'currency':
                $raw = $attributes['value'];

                if (! is_numeric($raw)) {
                    return '';
                }

                return preg_match('/^-?\d{1,15}$/', $raw) ? (string) (int) $raw : CellValue::number((float) $raw);

            case 'boolean':
                return in_array(strtolower($attributes['boolean']), ['true', '1'], true) ? '1' : '0';

            case 'date':
                $raw = $attributes['date'];

                if (! preg_match('/^(-?\d{4,}-\d{2}-\d{2})(?:T(\d{2}:\d{2}:\d{2}))?/', $raw, $m)) {
                    return $raw;
                }

                return (isset($m[2]) && $m[2] !== '00:00:00') ? "{$m[1]} {$m[2]}" : $m[1];

            case 'time':
                $raw = $attributes['time'];

                if (! preg_match('/^(-)?PT?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:[.,]\d+)?S)?$/', $raw, $m)) {
                    return $raw;
                }

                return ($m[1] ?? '') . sprintf('%02d:%02d:%02d', (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (int) ($m[4] ?? 0));

            case 'string':
                if ($stringValue !== null) {
                    if (strlen($stringValue) > XmlStream::MAX_CELL_BYTES) {
                        throw XmlStream::tooLong();
                    }

                    return $stringValue;
                }

                return $text;

            default:
                return $text;
        }
    }

    /**
     * The shown text of the cell the reader stands on: its paragraphs (text:p, text:h) joined
     * by line breaks, with spaces, tabs and line breaks written out and notes left out. Every
     * node of the cell is read (and counted) even when the text is not needed.
     */
    private function cellText(XMLReader $xml, bool $needed): string
    {
        if ($xml->isEmptyElement) {
            return '';
        }

        $budget = $this->budget;
        $depth = $xml->depth;
        $nodes = 0;
        $text = null;

        // The depth of the paragraph being read, and of an element whose content is left out.
        $paragraph = -1;
        $skip = -1;

        while ($xml->read() && XmlStream::count($xml, $budget)) {
            if (++$nodes > XmlStream::MAX_CELL_NODES) {
                throw XmlStream::tooComplex();
            }

            $node = $xml->nodeType;
            $at = $xml->depth;

            if ($node === XMLReader::END_ELEMENT) {
                if ($at === $depth) {
                    break;
                }

                if ($at === $skip) {
                    $skip = -1;
                } elseif ($at === $paragraph) {
                    $paragraph = -1;
                }

                continue;
            }

            if (! $needed || $skip >= 0) {
                continue;
            }

            if ($node === XMLReader::ELEMENT) {
                $empty = $xml->isEmptyElement;

                if ($at === $depth + 1) {
                    if ($xml->namespaceURI === self::TEXT && ($xml->localName === 'p' || $xml->localName === 'h')) {
                        $text = $text === null ? '' : $text . "\n";

                        if (! $empty) {
                            $paragraph = $at;
                        }
                    } elseif (! $empty) {
                        $skip = $at;
                    }

                    continue;
                }

                if ($paragraph < 0) {
                    continue;
                }

                $text ??= '';
                $piece = match ($xml->namespaceURI . '#' . $xml->localName) {
                    // The space count is clamped: c="2000000000" would ask for 2 GB.
                    self::TEXT . '#s' => str_repeat(' ', min(SpreadsheetReader::MAX_CELL_LENGTH + 1, max(1, (int) ($xml->getAttributeNs('c', self::TEXT) ?: 1)))),
                    self::TEXT . '#tab' => "\t",
                    self::TEXT . '#line-break' => "\n",
                    self::OFFICE . '#annotation', self::TEXT . '#note' => '',
                    default => null,
                };

                if ($piece !== null) {
                    XmlStream::append($text, $piece);

                    // Only the written-out piece counts; whatever such an element holds is left out.
                    if (! $empty) {
                        $skip = $at;
                    }
                }

                continue;
            }

            if ($paragraph >= 0 && XmlStream::isText($xml)) {
                $text ??= '';
                XmlStream::append($text, $xml->value);
            }
        }

        return $text ?? '';
    }

    /**
     * XMLReader stops at broken XML (or at the nesting limit) without an exception; this
     * turns that into one, so a damaged sheet is never read as a shorter one.
     */
    private function assertWellFormed(): void
    {
        $error = libxml_get_last_error();

        if ($error !== false && $error->level >= LIBXML_ERR_ERROR) {
            libxml_clear_errors();

            throw UnreadableSpreadsheet::because('unreadable');
        }
    }

    private function is(XMLReader $xml, string $namespace, string $name): bool
    {
        return $xml->localName === $name && $xml->namespaceURI === $namespace;
    }

    /**
     * Without LIBXML_PARSEHUGE: libxml keeps its nesting limit (256 levels), so a cell nested
     * 50,000 levels deep is a clean parse error instead of a stack overflow in expand().
     */
    private function open(): XMLReader
    {
        SpreadsheetReader::assertXmlPart($this->path, 'content.xml');
        libxml_clear_errors();
        $xml = new XMLReader;

        if (! @$xml->open('zip://' . $this->path . '#content.xml', null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return $xml;
    }
}

<?php

namespace PNScripts\SheafgateLite\Reader;

use Closure;
use DateTimeImmutable;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use XMLReader;
use ZipArchive;

/**
 * A streaming reader for XLSX workbooks (SpreadsheetML), written for speed: one pass over
 * the sheet XML with XMLReader, shared strings and number formats read once.
 *
 * It reads what an import needs: text, numbers, booleans, dates and times (1900 and 1904
 * date systems, built-in and custom date formats), durations, formulas as their saved
 * result (marked when there is none), error cells, hidden sheets and merged header cells.
 *
 * Every part is read node by node (XmlStream) and every node counts against the reader's
 * Budget: no part, cell or string is ever built as a DOM, and text is gathered one text
 * node at a time up to the most a cell can hold.
 */
final class XlsxSheets
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Built-in number formats that show dates or times. */
    private const DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /** @var list<array{name: string, path: string, visible: bool}>|null */
    private ?array $sheets = null;

    /** @var array<string, string>|null relationship type (last segment) => target, and "id:<Id>" => target */
    private ?array $relations = null;

    /** The last column (XFD) and row a spreadsheet can have. */
    public const MAX_COLUMNS = 16384;

    public const MAX_ROWS = 1048576;

    /**
     * Shared strings are kept packed: all texts in one string, their start offsets in another.
     * 2,000,000 is 40 different texts on every row of a 50,000-row sheet, and reading them
     * all takes a few seconds; a file that declares more is refused.
     */
    public const MAX_SHARED_STRINGS = 2000000;

    /** Packed shared-string text kept at most; with the offsets it fits PHP's default 128 MB. */
    public const MAX_SHARED_STRING_BYTES = 32 * 1024 * 1024;

    /**
     * Workbook and relationship parts: a real one is a few kilobytes, so anything above this
     * is refused before it is read.
     */
    public const MAX_DOM_PART_BYTES = 4 * 1024 * 1024;

    /** More cell formats or number formats than Excel allows (64,000) in one workbook. */
    public const MAX_STYLES = 100000;

    /** Excel's own limit on a number format code; a longer one is ignored (never kept). */
    public const MAX_FORMAT_CODE = 255;

    /** Merged ranges looked at, at most (only those on the header row are used). */
    public const MAX_MERGES = 100000;

    private ?string $stringData = null;

    private string $stringOffsets = '';

    private int $stringCount = 0;

    /** The open shared strings part while it has not been read to the end. */
    private ?XMLReader $stringReader = null;

    /** @var array<int, string>|null style index => "date", "duration" or "" */
    private ?array $styleKinds = null;

    private bool $date1904 = false;

    public function __construct(private readonly string $path, private readonly Budget $budget) {}

    /**
     * @return list<array{name: string, path: string, visible: bool}>
     */
    public function sheets(): array
    {
        if ($this->sheets !== null) {
            return $this->sheets;
        }

        if (! $this->exists('xl/workbook.xml')) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        $relations = $this->relations();
        $xml = $this->open('xl/workbook.xml', self::MAX_DOM_PART_BYTES);
        $sheets = [];
        $this->date1904 = false;

        try {
            while ($xml->read() && XmlStream::count($xml, $this->budget)) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->namespaceURI !== self::MAIN) {
                    continue;
                }

                if ($xml->localName === 'workbookPr') {
                    $this->date1904 = in_array(strtolower((string) $xml->getAttribute('date1904')), ['1', 'true'], true);
                } elseif ($xml->localName === 'sheet') {
                    $target = $relations['id:' . $xml->getAttributeNs('id', self::REL)] ?? null;

                    if ($target !== null) {
                        $sheets[] = [
                            'name' => (string) $xml->getAttribute('name'),
                            'path' => $target,
                            'visible' => ! in_array($xml->getAttribute('state'), ['hidden', 'veryHidden'], true),
                        ];
                    }

                    if (count($sheets) > SpreadsheetReader::MAX_SHEETS) {
                        throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(SpreadsheetReader::$maxEntryBytes / 1024 / 1024)]);
                    }
                }
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }

        return $this->sheets = $sheets;
    }

    /**
     * The workbook's relationships, read once: by Id ("id:rId1") and by type ("sharedStrings").
     *
     * @return array<string, string>
     */
    private function relations(): array
    {
        if ($this->relations !== null) {
            return $this->relations;
        }

        $relations = [];

        if ($this->exists('xl/_rels/workbook.xml.rels')) {
            $xml = $this->open('xl/_rels/workbook.xml.rels', self::MAX_DOM_PART_BYTES);

            try {
                while ($xml->read() && XmlStream::count($xml, $this->budget)) {
                    if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'Relationship') {
                        continue;
                    }

                    $target = ltrim((string) $xml->getAttribute('Target'), '/');
                    $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                    $relations['id:' . $xml->getAttribute('Id')] = $target;
                    $type = (string) $xml->getAttribute('Type');
                    $slash = strrpos($type, '/');
                    $relations[$slash === false ? $type : substr($type, $slash + 1)] ??= $target;

                    if (count($relations) > 2 * SpreadsheetReader::MAX_SHEETS + 20) {
                        throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(SpreadsheetReader::$maxEntryBytes / 1024 / 1024)]);
                    }
                }

                $this->assertWellFormed();
            } finally {
                $xml->close();
            }
        }

        return $this->relations = $relations;
    }

    /**
     * Rows of one sheet as text, keyed by their row number; empty rows are left out.
     *
     * Rows are read cell by cell, never built whole: a row may hold at most MAX_COLUMNS
     * cells, and only the first $width columns are kept (a row whose only values lie past
     * them is still yielded, as an empty list, so it counts as a row). Without a width (the
     * preview, before the header is known) the first SpreadsheetReader::PREVIEW_COLUMNS
     * columns are kept and whitespace-only cells after the last filled one are dropped.
     *
     * $onFlag hears of a row with a value past the kept columns ("wide"). With $only, just
     * that row is read: the rows above it are stepped over without reading their cells.
     *
     * @param  (Closure(int, string): void)|null  $onFlag
     * @return \Generator<int, list<string>>
     */
    public function rows(string $sheet, ?int $width = null, ?int $only = null, ?Closure $onFlag = null): \Generator
    {
        $path = $this->sheetPath($sheet);
        $this->loadSharedStrings();
        $kinds = $this->styleKinds();
        $xml = $this->open($path);
        $number = 0;
        $budget = $this->budget;

        try {
            $moved = $xml->read() && XmlStream::count($xml, $budget);

            while ($moved) {
                if ($xml->nodeType !== XMLReader::ELEMENT) {
                    $moved = $xml->read() && XmlStream::count($xml, $budget);

                    continue;
                }

                $budget->row();

                if ($xml->namespaceURI !== self::MAIN) {
                    $moved = $xml->read() && XmlStream::count($xml, $budget);

                    continue;
                }

                if ($xml->localName === 'mergeCells') {
                    break;
                }

                if ($xml->localName !== 'row') {
                    $moved = $xml->read() && XmlStream::count($xml, $budget);

                    continue;
                }

                $number = (int) ($xml->getAttribute('r') ?? 0) ?: $number + 1;

                if ($number > self::MAX_ROWS) {
                    throw UnreadableSpreadsheet::because('too_large_sheet');
                }

                if ($only !== null && $number !== $only) {
                    if ($number > $only) {
                        break;
                    }

                    // A row above the wanted one: stepped over without reading its cells.
                    $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);

                    continue;
                }

                if ($xml->isEmptyElement) {
                    $moved = $xml->read() && XmlStream::count($xml, $budget);

                    continue;
                }

                [$cells, $filled, $beyond] = $this->streamRow($xml, $kinds, $width);
                $moved = $xml->read() && XmlStream::count($xml, $budget);

                if ($beyond && $onFlag !== null) {
                    $onFlag($number, 'wide');
                }

                if ($filled) {
                    yield $number => $cells;
                }

                if ($only !== null) {
                    break;
                }
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }
    }

    /**
     * Merged ranges of a sheet as "A1:D1" references.
     *
     * Merges sit after all the cells, so they are found by scanning the part's bytes for
     * <mergeCell ref="…"> instead of walking every cell node again; at most MAX_MERGES.
     *
     * @return list<string>
     */
    public function merges(string $sheet): array
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        $merges = [];

        try {
            $stream = $zip->locateName($this->sheetPath($sheet)) === false ? false : $zip->getStream($this->sheetPath($sheet));

            if ($stream === false) {
                return [];
            }

            $tail = '';
            $keep = 512;

            try {
                while (count($merges) < self::MAX_MERGES) {
                    $chunk = feof($stream) ? '' : fread($stream, 1024 * 1024);
                    $last = $chunk === '' || $chunk === false;
                    $buffer = $tail . ($last ? '' : $chunk);
                    $limit = $last ? strlen($buffer) : strlen($buffer) - $keep;

                    if (preg_match_all('/<(?:[A-Za-z_][\w.-]{0,40}:)?mergeCell\s[^>]{0,400}?\bref\s*=\s*["\']([A-Za-z]{1,3}[0-9]{1,7}:[A-Za-z]{1,3}[0-9]{1,7})["\']/', $buffer, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                        foreach ($found as $match) {
                            if ($match[0][1] < $limit && count($merges) < self::MAX_MERGES) {
                                $merges[] = $match[1][0];
                            }
                        }
                    }

                    if ($last) {
                        break;
                    }

                    $tail = substr($buffer, max(0, $limit));
                }
            } finally {
                fclose($stream);
            }
        } finally {
            $zip->close();
        }

        return $merges;
    }

    /**
     * Reads the cells of the row the reader stands on and leaves it on the row's end tag.
     *
     * @param  array<int, string>  $kinds
     * @return array{0: list<string>, 1: bool, 2: bool} the kept cells, whether the row has any
     *                                                  value, and whether it has one past the kept columns
     */
    private function streamRow(XMLReader $xml, array $kinds, ?int $width): array
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
        $count = 0;
        $next = 0;
        $bytes = 0;
        $moved = $xml->read() && XmlStream::count($xml, $budget);

        while ($moved) {
            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) {
                break;
            }

            if ($xml->nodeType !== XMLReader::ELEMENT) {
                $moved = $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            if ($xml->localName !== 'c' || $xml->namespaceURI !== self::MAIN || $xml->depth !== $depth + 1) {
                $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            // A row can never hold more cells than a sheet has columns.
            if (++$count > self::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            // An empty cell (<c r="B2" s="3"/>) has no value, except an error cell without its
            // code; it counts half a cell, and allows half the node it is.
            $empty = $xml->isEmptyElement && $xml->getAttribute('t') !== 'e';
            $empty ? $budget->emptyCell() : $budget->cells();

            // The column from the reference's letters (strspn, not a regex: this runs for
            // every cell); more than three letters is past XFD, no reference is the next column.
            $reference = (string) $xml->getAttribute('r');
            $letters = $reference === '' ? 0 : strspn($reference, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ');
            $column = match (true) {
                $letters === 0 => $next,
                $letters > 3 => self::MAX_COLUMNS,
                default => Headers::columnIndex(substr($reference, 0, $letters)),
            };
            $next = $column + 1;

            if ($column >= self::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            $outside = $column >= $keep;

            // Past the kept columns only one thing matters: whether the row has a value there.
            if ($outside && $beyond) {
                $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            if ($empty) {
                $moved = $xml->read() && XmlStream::count($xml, $budget);

                continue;
            }

            $value = $this->cellValue($xml, $kinds);
            $moved = $xml->read() && XmlStream::count($xml, $budget);

            if ($value === '') {
                continue;
            }

            $budget->value();

            $filled = true;

            // Past the kept columns a whitespace-only value is no reason to call the row wide.
            if ($outside) {
                $beyond = trim($value) !== '';

                continue;
            }

            // The kept text of one row is bounded, so a row of long cells cannot fill memory.
            $bytes += strlen($value);

            if ($bytes > SpreadsheetReader::MAX_ROW_BYTES) {
                throw UnreadableSpreadsheet::because('row_too_large', replace: ['max' => (int) (SpreadsheetReader::MAX_ROW_BYTES / 1024 / 1024)]);
            }

            $cells[$column] = $value;

            // Without a width a whitespace-only far cell must not pad the row to it.
            if ($column > $last && (! $trim || trim($value) !== '')) {
                $last = $column;
            }
        }

        $row = [];

        for ($i = 0; $i <= $last; $i++) {
            $row[] = $cells[$i] ?? '';
        }

        return [$row, $filled, $beyond];
    }

    /**
     * The value of the cell the reader stands on, read node by node; leaves the reader on the
     * cell's end tag (or on the cell, when it is empty).
     *
     * @param  array<int, string>  $kinds
     */
    private function cellValue(XMLReader $xml, array $kinds): string
    {
        $type = $xml->getAttribute('t') ?: 'n';
        $style = (int) $xml->getAttribute('s');
        $formula = false;
        $raw = null;
        $inline = null;

        if (! $xml->isEmptyElement) {
            $budget = $this->budget;
            $depth = $xml->depth;
            $nodes = 0;
            $mode = '';

            // In an inline string: the depth of the <t> being read, and of an <rPh> being left out.
            $textDepth = -1;
            $hintDepth = -1;

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

                    if ($at === $depth + 1) {
                        $mode = '';
                    } elseif ($at === $textDepth) {
                        $textDepth = -1;
                    } elseif ($at === $hintDepth) {
                        $hintDepth = -1;
                    }

                    continue;
                }

                if ($node === XMLReader::ELEMENT) {
                    $main = $xml->namespaceURI === self::MAIN;

                    if ($at === $depth + 1) {
                        $mode = $main ? $xml->localName : '';

                        match ($mode) {
                            'f' => $formula = true,
                            'v' => $raw = '',
                            'is' => $inline = '',
                            default => null,
                        };

                        if ($xml->isEmptyElement) {
                            $mode = '';
                        }
                    } elseif ($mode === 'is' && $main && ! $xml->isEmptyElement) {
                        if ($xml->localName === 'rPh' && $hintDepth < 0) {
                            $hintDepth = $at;
                        } elseif ($xml->localName === 't' && $textDepth < 0) {
                            $textDepth = $at;
                        }
                    }

                    continue;
                }

                if (! XmlStream::isText($xml)) {
                    continue;
                }

                if ($mode === 'v') {
                    $raw ??= '';
                    XmlStream::append($raw, $xml->value);
                } elseif ($mode === 'is' && $textDepth >= 0 && $hintDepth < 0) {
                    $inline ??= '';
                    XmlStream::append($inline, $xml->value);
                }
            }
        }

        if ($type === 'inlineStr') {
            return $inline ?? '';
        }

        if ($type === 'e') {
            return CellValue::ERROR . trim((string) $raw);
        }

        if ($raw === null || $raw === '') {
            // A formula saved without its result (files written by libraries are never calculated).
            return $formula ? CellValue::FORMULA : '';
        }

        return match ($type) {
            's' => $this->sharedString((int) $raw),
            'str' => $raw,
            'b' => $raw === '1' || strtolower($raw) === 'true' ? '1' : '0',
            'd' => $this->isoDate($raw),
            default => $this->number($raw, $kinds[$style] ?? ''),
        };
    }

    private function number(string $raw, string $kind): string
    {
        if (! is_numeric($raw)) {
            return $raw;
        }

        $value = (float) $raw;

        if ($kind === 'duration') {
            $seconds = (int) round($value * 86400);
            $sign = $seconds < 0 ? '-' : '';
            $seconds = abs($seconds);

            return $sign . sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }

        if ($kind === 'date' && $value > -693594 && $value < 2958466) {
            $days = (int) floor($value);
            $seconds = (int) round(($value - $days) * 86400);

            if ($seconds === 86400) {
                $days++;
                $seconds = 0;
            }

            // Excel's 1900 system counts a 29 February 1900 that never was (serial 60), so
            // serials 1 to 59 count from 31 December 1899 and later ones from the 30th.
            $base = match (true) {
                $this->date1904 => '1904-01-01',
                $days >= 1 && $days < 60 => '1899-12-31',
                default => '1899-12-30',
            };

            $date = (new DateTimeImmutable($base))
                ->modify(($days >= 0 ? '+' : '') . $days . ' days')
                ->modify('+' . $seconds . ' seconds');

            return CellValue::dateTime($date);
        }

        if (preg_match('/^-?\d{1,15}$/', $raw)) {
            return (string) (int) $raw;
        }

        return CellValue::number($value);
    }

    private function isoDate(string $raw): string
    {
        if (! preg_match('/^(-?\d{4,}-\d{2}-\d{2})(?:T(\d{2}:\d{2}:\d{2}))?/', $raw, $m)) {
            return $raw;
        }

        return (isset($m[2]) && $m[2] !== '00:00:00') ? "{$m[1]} {$m[2]}" : $m[1];
    }

    private function sharedString(int $index): string
    {
        if ($index >= self::MAX_SHARED_STRINGS) {
            throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) (self::MAX_SHARED_STRING_BYTES / 1024 / 1024)]);
        }

        if ($index >= $this->stringCount) {
            $this->readStringsUpTo($index);
        }

        if ($this->stringData === null || $index < 0 || $index >= $this->stringCount) {
            return '';
        }

        $start = unpack('V', substr($this->stringOffsets, $index * 4, 4));
        $end = $index + 1 < $this->stringCount ? unpack('V', substr($this->stringOffsets, ($index + 1) * 4, 4)) : [1 => strlen($this->stringData)];

        if (! is_array($start) || ! is_array($end) || ! is_int($start[1]) || ! is_int($end[1])) {
            return '';
        }

        return substr($this->stringData, $start[1], $end[1] - $start[1]);
    }

    /**
     * Prepares the shared strings. They are read lazily, only as far as the highest index a
     * cell has asked for (Excel writes them in the order they first appear, so the first rows
     * of a sheet need only the first strings), into one packed string (a PHP array of
     * millions of strings needs several times the memory), with limits on their number and size.
     */
    private function loadSharedStrings(): void
    {
        if ($this->stringData !== null) {
            return;
        }

        $this->stringData = '';
        $path = $this->relations()['sharedStrings'] ?? 'xl/sharedStrings.xml';

        if ($this->exists($path)) {
            $this->stringReader = $this->open($path);
        }
    }

    /**
     * Reads shared strings until the one with the given index is read or the list ends.
     */
    private function readStringsUpTo(int $index): void
    {
        $xml = $this->stringReader;

        if ($xml === null || $this->stringData === null) {
            return;
        }

        $finished = false;

        // Appended in place: copying the packed strings into a local variable would double
        // their memory and copy them again on every call.
        try {
            while ($this->stringCount <= $index) {
                if (! $xml->read() || ! XmlStream::count($xml, $this->budget)) {
                    $finished = true;

                    break;
                }

                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'si' || $xml->namespaceURI !== self::MAIN) {
                    continue;
                }

                // Strings read to reach a late index give no values of their own: each item
                // brings its own allowance of nodes (a late string must not starve the sheet).
                $this->budget->stringItem();
                $this->stringOffsets .= pack('V', strlen($this->stringData));
                $this->stringData .= $this->streamedText($xml);
                $this->stringCount++;

                if ($this->stringCount > self::MAX_SHARED_STRINGS || strlen($this->stringData) > self::MAX_SHARED_STRING_BYTES) {
                    $finished = true;

                    throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) (self::MAX_SHARED_STRING_BYTES / 1024 / 1024)]);
                }
            }
        } finally {
            if ($finished) {
                $xml->close();
                $this->stringReader = null;
            }
        }

        if ($finished) {
            $this->assertWellFormed();
        }
    }

    public function __destruct()
    {
        $this->stringReader?->close();
    }

    /**
     * Text of the string item the reader stands on, read node by node: its runs joined,
     * phonetic hints (rPh) left out, refused past the most a cell can hold. Leaves the reader
     * on the item's end tag.
     */
    private function streamedText(XMLReader $xml): string
    {
        if ($xml->isEmptyElement) {
            return '';
        }

        $depth = $xml->depth;
        $text = '';
        $nodes = 0;
        $textDepth = -1;
        $hintDepth = -1;

        while ($xml->read() && XmlStream::count($xml, $this->budget)) {
            if (++$nodes > XmlStream::MAX_CELL_NODES) {
                throw XmlStream::tooComplex();
            }

            $node = $xml->nodeType;

            if ($node === XMLReader::END_ELEMENT) {
                if ($xml->depth === $depth) {
                    break;
                }

                if ($xml->depth === $textDepth) {
                    $textDepth = -1;
                } elseif ($xml->depth === $hintDepth) {
                    $hintDepth = -1;
                }

                continue;
            }

            if ($node === XMLReader::ELEMENT) {
                if ($xml->namespaceURI === self::MAIN && ! $xml->isEmptyElement) {
                    if ($xml->localName === 'rPh' && $hintDepth < 0) {
                        $hintDepth = $xml->depth;
                    } elseif ($xml->localName === 't' && $textDepth < 0) {
                        $textDepth = $xml->depth;
                    }
                }

                continue;
            }

            if ($textDepth >= 0 && $hintDepth < 0 && XmlStream::isText($xml)) {
                XmlStream::append($text, $xml->value);
            }
        }

        return $text;
    }

    /**
     * Which cell styles show dates or durations.
     *
     * @return array<int, string>
     */
    private function styleKinds(): array
    {
        if ($this->styleKinds !== null) {
            return $this->styleKinds;
        }

        $path = $this->relations()['styles'] ?? 'xl/styles.xml';

        if (! $this->exists($path)) {
            return $this->styleKinds = [];
        }

        // Only number formats and the cell formats (cellXfs) are kept; every other node is
        // stepped over and counted. A format code is kept only as a short kind ("date").
        $xml = $this->open($path);
        $budget = $this->budget;
        $kindsById = [];
        $formats = [];
        $inFormats = false;
        $seenFormats = false;

        try {
            $moved = $xml->read() && XmlStream::count($xml, $budget);

            while ($moved) {
                if ($xml->nodeType === XMLReader::END_ELEMENT && $inFormats && $xml->depth === 1) {
                    $inFormats = false;
                    $seenFormats = true;
                }

                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->depth === 0) {
                    $moved = $xml->read() && XmlStream::count($xml, $budget);

                    continue;
                }

                $name = $xml->namespaceURI === self::MAIN ? $xml->localName : '';

                if ($xml->depth === 1) {
                    if ($name === 'cellXfs' && ! $seenFormats && ! $xml->isEmptyElement) {
                        $inFormats = true;
                        $moved = $xml->read() && XmlStream::count($xml, $budget);
                    } elseif ($name === 'numFmts') {
                        $moved = $xml->read() && XmlStream::count($xml, $budget);
                    } else {
                        $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);
                    }

                    continue;
                }

                if ($xml->depth === 2 && $name === 'numFmt') {
                    $code = (string) $xml->getAttribute('formatCode');
                    $kindsById[(int) $xml->getAttribute('numFmtId')] = strlen($code) > self::MAX_FORMAT_CODE ? '' : self::formatKind($code);
                } elseif ($xml->depth === 2 && $inFormats && $name === 'xf') {
                    $formats[] = (int) $xml->getAttribute('numFmtId');
                }

                if (count($kindsById) > self::MAX_STYLES || count($formats) > self::MAX_STYLES) {
                    throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(SpreadsheetReader::$maxEntryBytes / 1024 / 1024)]);
                }

                $moved = XmlStream::skip($xml, $budget) && $xml->read() && XmlStream::count($xml, $budget);
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }

        $kinds = [];

        foreach ($formats as $index => $id) {
            $kinds[$index] = match (true) {
                isset($kindsById[$id]) => $kindsById[$id],
                $id === 46 => 'duration',
                in_array($id, self::DATE_FORMAT_IDS, true) => 'date',
                default => '',
            };
        }

        return $this->styleKinds = $kinds;
    }

    /**
     * "date" for date and time formats, "duration" for [h]:mm style formats, "" otherwise.
     */
    public static function formatKind(string $code): string
    {
        $code = strtolower($code);

        if (preg_match('/\[(h|hh|m|mm|s|ss)\]/', $code)) {
            return 'duration';
        }

        // Quoted text, escaped characters and [colour]/[$-locale] parts do not make a date.
        $code = (string) preg_replace(['/"[^"]*"/', '/\\\\./', '/\[[^\]]*\]/', '/_./', '/\*./'], '', $code);

        if (str_contains($code, 'general')) {
            return '';
        }

        return preg_match('/[dmyhs]/', $code) ? 'date' : '';
    }

    /**
     * The workbook part a sheet's cells are in (several sheets may name the same one).
     */
    public function part(string $sheet): string
    {
        return $this->sheetPath($sheet);
    }

    private function sheetPath(string $sheet): string
    {
        foreach ($this->sheets() as $candidate) {
            if ($candidate['name'] === $sheet) {
                return $candidate['path'];
            }
        }

        throw UnreadableSpreadsheet::because('unreadable');
    }

    private function exists(string $entry): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $found = $zip->locateName($entry) !== false;
        $zip->close();

        return $found;
    }

    /**
     * Refuses a part that unpacks to more than $max bytes (its real bytes, read in chunks).
     */
    private function assertPartSize(string $entry, int $max): void
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        try {
            $stream = $zip->getStream($entry);

            if ($stream === false) {
                throw UnreadableSpreadsheet::because('unreadable');
            }

            $size = 0;

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $size += strlen($chunk);

                    if ($size > $max) {
                        throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(SpreadsheetReader::$maxEntryBytes / 1024 / 1024)]);
                    }
                }
            } finally {
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * XMLReader stops at broken XML without an exception; this turns that into one.
     */
    private function assertWellFormed(): void
    {
        if (libxml_get_last_error() !== false) {
            libxml_clear_errors();

            throw UnreadableSpreadsheet::because('unreadable');
        }
    }

    /**
     * Without LIBXML_PARSEHUGE: libxml keeps its nesting limit (256 levels) and its 10 MB
     * limit on one text node, so a deep or huge node is a clean parse error.
     */
    private function open(string $entry, ?int $maxBytes = null): XMLReader
    {
        if ($maxBytes !== null) {
            $this->assertPartSize($entry, $maxBytes);
        }

        SpreadsheetReader::assertXmlPart($this->path, $entry);
        libxml_clear_errors();
        $xml = new XMLReader;

        if (! @$xml->open('zip://' . $this->path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return $xml;
    }
}

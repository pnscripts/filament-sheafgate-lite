<?php

namespace PNScripts\SheafgateLite\Reader;

use Closure;
use DateTimeImmutable;
use DOMElement;
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
 */
final class XlsxSheets
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** Built-in number formats that show dates or times. */
    private const DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

    /** @var list<array{name: string, path: string, visible: bool}>|null */
    private ?array $sheets = null;

    /** The last column (XFD) and row a spreadsheet can have. */
    public const MAX_COLUMNS = 16384;

    public const MAX_ROWS = 1048576;

    /**
     * Shared strings are kept packed: all texts in one string, their start offsets in another.
     * 2,000,000 is 40 different texts on every row of a 50,000-row sheet, and reading them
     * all takes a few seconds; a file that declares more is refused.
     */
    public const MAX_SHARED_STRINGS = 2000000;

    public const MAX_SHARED_STRING_BYTES = 64 * 1024 * 1024;

    /**
     * Workbook, relationship and similar small parts are read whole into a DOM; a real one is
     * a few kilobytes, so anything above this is refused before it is parsed.
     */
    public const MAX_DOM_PART_BYTES = 4 * 1024 * 1024;

    /** More cell formats or number formats than Excel allows (64,000) in one workbook. */
    public const MAX_STYLES = 100000;

    private ?string $stringData = null;

    private string $stringOffsets = '';

    private int $stringCount = 0;

    /** The open shared strings part while it has not been read to the end. */
    private ?XMLReader $stringReader = null;

    /** @var array<int, string>|null style index => "date", "duration" or "" */
    private ?array $styleKinds = null;

    private bool $date1904 = false;

    /**
     * @param  (Closure(): void)|null  $onCell  called for every cell element read (the caller's budget)
     */
    public function __construct(private readonly string $path, private readonly ?Closure $onCell = null) {}

    /**
     * @return list<array{name: string, path: string, visible: bool}>
     */
    public function sheets(): array
    {
        if ($this->sheets !== null) {
            return $this->sheets;
        }

        $workbook = $this->entry('xl/workbook.xml');
        $rels = $this->entry('xl/_rels/workbook.xml.rels');

        if ($workbook === null) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        $targets = [];
        $relsDom = $rels === null ? null : $this->dom($rels);

        foreach ($relsDom?->getElementsByTagName('Relationship') ?? [] as $relation) {
            $target = ltrim($relation->getAttribute('Target'), '/');
            $targets[$relation->getAttribute('Id')] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }

        $dom = $this->dom($workbook);
        $this->date1904 = false;

        foreach ($dom->getElementsByTagNameNS(self::MAIN, 'workbookPr') as $properties) {
            $this->date1904 = in_array(strtolower($properties->getAttribute('date1904')), ['1', 'true'], true);
        }

        $sheets = [];

        foreach ($dom->getElementsByTagNameNS(self::MAIN, 'sheet') as $sheet) {
            $id = $sheet->getAttributeNS(self::REL, 'id');

            if (! isset($targets[$id])) {
                continue;
            }

            $sheets[] = [
                'name' => $sheet->getAttribute('name'),
                'path' => $targets[$id],
                'visible' => ! in_array($sheet->getAttribute('state'), ['hidden', 'veryHidden'], true),
            ];
        }

        return $this->sheets = $sheets;
    }

    /**
     * Rows of one sheet as text, keyed by their row number; empty rows are left out.
     *
     * Rows are read cell by cell, never built whole: a row may hold at most MAX_COLUMNS
     * cells, and with a width only the first $width columns are kept (a row whose only
     * values lie past it is still yielded, as an empty list, so it counts as a row).
     * Without a width, whitespace-only cells after the last filled one are dropped, never padded.
     *
     * Every element outside the rows' cells, empty rows included, is passed to $onElement
     * (the caller's budget). With $only, just that row is read: the rows above it are skipped
     * unread and reading stops after it.
     *
     * @param  (Closure(): void)|null  $onElement
     * @return \Generator<int, list<string>>
     */
    public function rows(string $sheet, ?int $width = null, ?Closure $onElement = null, ?int $only = null): \Generator
    {
        $path = $this->sheetPath($sheet);
        $this->loadSharedStrings();
        $kinds = $this->styleKinds();
        $xml = $this->open($path);
        $number = 0;

        try {
            $moved = $xml->read();

            while ($moved) {
                if ($xml->nodeType !== XMLReader::ELEMENT) {
                    $moved = $xml->read();

                    continue;
                }

                if ($onElement !== null) {
                    $onElement();
                }

                if ($xml->namespaceURI !== self::MAIN) {
                    $moved = $xml->read();

                    continue;
                }

                if ($xml->localName === 'mergeCells') {
                    break;
                }

                if ($xml->localName !== 'row') {
                    $moved = $xml->read();

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

                    // A row above the wanted one: skipped without reading its cells.
                    $moved = $xml->next();

                    continue;
                }

                if ($xml->isEmptyElement) {
                    $moved = $xml->read();

                    continue;
                }

                [$cells, $filled] = $this->streamRow($xml, $kinds, $width);
                $moved = $xml->read();

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
     * @return list<string>
     */
    public function merges(string $sheet): array
    {
        $xml = $this->open($this->sheetPath($sheet));
        $merges = [];

        try {
            $moved = $xml->read();

            while ($moved) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->namespaceURI === self::MAIN) {
                    if ($xml->localName === 'sheetData') {
                        // Skip the cells without building them: only the merge list is needed.
                        $moved = $xml->next();

                        continue;
                    }

                    if ($xml->localName === 'mergeCell') {
                        $merges[] = (string) $xml->getAttribute('ref');
                    }
                }

                $moved = $xml->read();
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }

        return $merges;
    }

    /**
     * Reads the cells of the row the reader stands on and leaves it on the row's end tag.
     *
     * @param  array<int, string>  $kinds
     * @return array{0: list<string>, 1: bool} the kept cells, and whether the row has any value
     */
    private function streamRow(XMLReader $xml, array $kinds, ?int $width): array
    {
        $depth = $xml->depth;
        /** @var array<int, string> $cells kept values by column, padded only at the end */
        $cells = [];
        $last = -1;
        $filled = false;
        $count = 0;
        $next = 0;
        $moved = $xml->read();

        while ($moved) {
            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) {
                break;
            }

            if ($xml->nodeType !== XMLReader::ELEMENT) {
                $moved = $xml->read();

                continue;
            }

            if ($xml->localName !== 'c' || $xml->namespaceURI !== self::MAIN || $xml->depth !== $depth + 1) {
                $moved = $xml->next();

                continue;
            }

            // A row can never hold more cells than a sheet has columns.
            if (++$count > self::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            if ($this->onCell !== null) {
                ($this->onCell)();
            }

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

            $outside = $width !== null && $column >= $width;

            // Past the width only one value matters: whether the row has any at all.
            if ($outside && $filled) {
                $moved = $xml->next();

                continue;
            }

            // An empty cell (<c r="B2" s="3"/>) has no value, except an error cell without
            // its code: skipped without building it.
            if ($xml->isEmptyElement && $xml->getAttribute('t') !== 'e') {
                $moved = $xml->next();

                continue;
            }

            // A cell past libxml's nesting limit does not expand: the parse error ends the read.
            $cell = @$xml->expand();
            $moved = $xml->next();
            $value = $cell instanceof DOMElement ? $this->value($cell, $kinds) : '';

            if ($value === '') {
                continue;
            }

            $filled = true;

            if ($outside) {
                continue;
            }

            $cells[$column] = $value;

            // Without a width a whitespace-only far cell must not pad the row to it.
            if ($column > $last && ($width !== null || trim($value) !== '')) {
                $last = $column;
            }
        }

        $row = [];

        for ($i = 0; $i <= $last; $i++) {
            $row[] = $cells[$i] ?? '';
        }

        return [$row, $filled];
    }

    /**
     * @param  array<int, string>  $kinds
     */
    private function value(DOMElement $cell, array $kinds): string
    {
        $type = $cell->getAttribute('t') ?: 'n';
        $formula = null;
        $raw = null;
        $inline = null;

        foreach ($cell->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            match ($child->localName) {
                'f' => $formula = $child->textContent,
                'v' => $raw = $child->textContent,
                'is' => $inline = $this->richText($child),
                default => null,
            };
        }

        if ($type === 'inlineStr') {
            return $inline ?? '';
        }

        if ($type === 'e') {
            return CellValue::ERROR . trim((string) $raw);
        }

        if ($raw === null || $raw === '') {
            // A formula saved without its result (files written by libraries are never calculated).
            return $formula !== null ? CellValue::FORMULA : '';
        }

        return match ($type) {
            's' => $this->sharedString((int) $raw),
            'str' => $raw,
            'b' => $raw === '1' || strtolower($raw) === 'true' ? '1' : '0',
            'd' => $this->isoDate($raw),
            default => $this->number($raw, $kinds[(int) $cell->getAttribute('s')] ?? ''),
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
        $path = $this->relatedPart('sharedStrings') ?? 'xl/sharedStrings.xml';

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
                if (! $xml->read()) {
                    $finished = true;

                    break;
                }

                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'si' || $xml->namespaceURI !== self::MAIN) {
                    continue;
                }

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
     * Text of the string item the reader stands on, read without building it as a DOM
     * (several times faster for millions of items): its runs joined, phonetic hints (rPh)
     * left out. Leaves the reader on the item's end tag.
     */
    private function streamedText(XMLReader $xml): string
    {
        if ($xml->isEmptyElement) {
            return '';
        }

        $depth = $xml->depth;
        $text = '';
        $moved = $xml->read();

        while ($moved && ! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
            if ($xml->nodeType === XMLReader::ELEMENT && $xml->namespaceURI === self::MAIN && ($xml->localName === 't' || $xml->localName === 'rPh')) {
                if ($xml->localName === 't') {
                    $text .= $xml->readString();
                }

                $moved = $xml->next();

                continue;
            }

            $moved = $xml->read();
        }

        return $text;
    }

    /**
     * Text of a string item: its runs joined, phonetic hints (rPh) left out.
     */
    private function richText(DOMElement $item): string
    {
        $text = '';

        foreach ($item->getElementsByTagNameNS(self::MAIN, 't') as $node) {
            $parent = $node->parentNode;

            if ($parent instanceof DOMElement && $parent->localName === 'rPh') {
                continue;
            }

            $text .= $node->textContent;
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

        $path = $this->relatedPart('styles') ?? 'xl/styles.xml';

        if (! $this->exists($path)) {
            return $this->styleKinds = [];
        }

        // Streamed: only number formats and the cell formats (cellXfs) are read; fonts, fills,
        // borders and the rest are skipped unread.
        $xml = $this->open($path);
        $codes = [];
        $formats = [];
        $inFormats = false;
        $seenFormats = false;

        try {
            $moved = $xml->read();

            while ($moved) {
                if ($xml->nodeType === XMLReader::END_ELEMENT && $inFormats && $xml->depth === 1) {
                    $inFormats = false;
                    $seenFormats = true;
                }

                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->depth === 0) {
                    $moved = $xml->read();

                    continue;
                }

                $name = $xml->namespaceURI === self::MAIN ? $xml->localName : '';

                if ($xml->depth === 1) {
                    if ($name === 'cellXfs' && ! $seenFormats && ! $xml->isEmptyElement) {
                        $inFormats = true;
                        $moved = $xml->read();
                    } elseif ($name === 'numFmts') {
                        $moved = $xml->read();
                    } else {
                        $moved = $xml->next();
                    }

                    continue;
                }

                if ($xml->depth === 2 && $name === 'numFmt') {
                    $codes[(int) $xml->getAttribute('numFmtId')] = (string) $xml->getAttribute('formatCode');
                } elseif ($xml->depth === 2 && $inFormats && $name === 'xf') {
                    $formats[] = (int) $xml->getAttribute('numFmtId');
                }

                if (count($codes) > self::MAX_STYLES || count($formats) > self::MAX_STYLES) {
                    throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(SpreadsheetReader::$maxEntryBytes / 1024 / 1024)]);
                }

                $moved = $xml->next();
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }

        $kinds = [];

        foreach ($formats as $index => $id) {
            $kinds[$index] = match (true) {
                isset($codes[$id]) => self::formatKind($codes[$id]),
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

    /**
     * The workbook's relationship target of a given type (sharedStrings, styles).
     */
    private function relatedPart(string $type): ?string
    {
        $rels = $this->entry('xl/_rels/workbook.xml.rels');

        if ($rels === null) {
            return null;
        }

        foreach ($this->dom($rels)->getElementsByTagName('Relationship') as $relation) {
            if (str_ends_with($relation->getAttribute('Type'), '/' . $type)) {
                $target = ltrim($relation->getAttribute('Target'), '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }

        return null;
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

    private function entry(string $entry): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        try {
            $stream = $zip->locateName($entry) === false ? false : $zip->getStream($entry);

            if ($stream === false) {
                return null;
            }

            $content = '';

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $content .= $chunk;

                    if (strlen($content) > self::MAX_DOM_PART_BYTES) {
                        throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(SpreadsheetReader::$maxEntryBytes / 1024 / 1024)]);
                    }
                }
            } finally {
                fclose($stream);
            }

            return $content;
        } finally {
            $zip->close();
        }
    }

    private function dom(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return $dom;
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
     * Without LIBXML_PARSEHUGE: libxml keeps its nesting limit (256 levels), so a cell nested
     * 50,000 levels deep is a clean parse error instead of a stack overflow in expand().
     */
    private function open(string $entry): XMLReader
    {
        libxml_clear_errors();
        $xml = new XMLReader;

        if (! @$xml->open('zip://' . $this->path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return $xml;
    }
}

<?php

namespace PNScripts\SheafgateLite\Reader;

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

    /** Shared strings are kept packed: all texts in one string, their start offsets in another. */
    public const MAX_SHARED_STRINGS = 5000000;

    public const MAX_SHARED_STRING_BYTES = 64 * 1024 * 1024;

    private ?string $stringData = null;

    private string $stringOffsets = '';

    private int $stringCount = 0;

    /** @var array<int, string>|null style index => "date", "duration" or "" */
    private ?array $styleKinds = null;

    private bool $date1904 = false;

    public function __construct(private readonly string $path) {}

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
     * @return \Generator<int, list<string>>
     */
    public function rows(string $sheet): \Generator
    {
        $path = $this->sheetPath($sheet);
        $this->loadSharedStrings();
        $kinds = $this->styleKinds();
        $xml = $this->open($path);
        $number = 0;

        try {
            $moved = $xml->read();

            while ($moved) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->namespaceURI !== self::MAIN) {
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
                $row = $xml->expand();
                $moved = $xml->next();

                if (! $row instanceof DOMElement) {
                    continue;
                }

                $cells = $this->cells($row, $kinds);

                if ($cells !== []) {
                    yield $number => $cells;
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
     * @param  array<int, string>  $kinds
     * @return list<string>
     */
    private function cells(DOMElement $row, array $kinds): array
    {
        $cells = [];
        $next = 0;

        foreach ($row->childNodes as $cell) {
            if (! $cell instanceof DOMElement || $cell->localName !== 'c') {
                continue;
            }

            $reference = $cell->getAttribute('r');
            $column = preg_match('/^([A-Z]{1,3})(?![A-Z])/', $reference, $m) ? Headers::columnIndex($m[1]) : (preg_match('/^[A-Z]/', $reference) ? self::MAX_COLUMNS : $next);
            $next = $column + 1;

            if ($column >= self::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            $value = $this->value($cell, $kinds);

            if ($value === '') {
                continue;
            }

            for ($i = count($cells); $i < $column; $i++) {
                $cells[] = '';
            }

            $cells[$column] = $value;
        }

        ksort($cells);

        return array_values($cells);
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

            $date = (new DateTimeImmutable($this->date1904 ? '1904-01-01' : '1899-12-30'))
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
     * Reads the shared strings into one packed string (a PHP array of millions of strings
     * needs several times the memory), with limits on their number and size.
     */
    private function loadSharedStrings(): void
    {
        if ($this->stringData !== null) {
            return;
        }

        $this->stringData = '';
        $path = $this->relatedPart('sharedStrings') ?? 'xl/sharedStrings.xml';

        if (! $this->exists($path)) {
            return;
        }

        $xml = $this->open($path);
        $data = '';
        $offsets = '';
        $count = 0;

        try {
            $moved = $xml->read();

            while ($moved) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'si' && $xml->namespaceURI === self::MAIN) {
                    $node = $xml->expand();
                    $offsets .= pack('V', strlen($data));
                    $data .= $node instanceof DOMElement ? $this->richText($node) : '';
                    $count++;

                    if ($count > self::MAX_SHARED_STRINGS || strlen($data) > self::MAX_SHARED_STRING_BYTES) {
                        throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) (self::MAX_SHARED_STRING_BYTES / 1024 / 1024)]);
                    }

                    $moved = $xml->next();

                    continue;
                }

                $moved = $xml->read();
            }

            $this->assertWellFormed();
        } finally {
            $xml->close();
        }

        $this->stringData = $data;
        $this->stringOffsets = $offsets;
        $this->stringCount = $count;
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
        $content = $this->exists($path) ? $this->entry($path) : null;

        if ($content === null) {
            return $this->styleKinds = [];
        }

        $dom = $this->dom($content);
        $codes = [];

        foreach ($dom->getElementsByTagNameNS(self::MAIN, 'numFmt') as $format) {
            $codes[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
        }

        $kinds = [];

        foreach ($dom->getElementsByTagNameNS(self::MAIN, 'cellXfs') as $list) {
            $index = 0;

            foreach ($list->childNodes as $xf) {
                if (! $xf instanceof DOMElement || $xf->localName !== 'xf') {
                    continue;
                }

                $id = (int) $xf->getAttribute('numFmtId');
                $kinds[$index++] = match (true) {
                    isset($codes[$id]) => self::formatKind($codes[$id]),
                    $id === 46 => 'duration',
                    in_array($id, self::DATE_FORMAT_IDS, true) => 'date',
                    default => '',
                };
            }

            break;
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

        $content = $zip->getFromName($entry);
        $zip->close();

        return is_string($content) ? $content : null;
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

    private function open(string $entry): XMLReader
    {
        libxml_clear_errors();
        $xml = new XMLReader;

        if (! @$xml->open('zip://' . $this->path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return $xml;
    }
}

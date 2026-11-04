<?php

namespace PNScripts\SheafgateLite\Reader;

use DOMElement;
use DOMNode;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use XMLReader;

/**
 * A small streaming reader for OpenDocument spreadsheets (content.xml).
 *
 * Written for this package because merged ("covered") cells must keep their column,
 * row numbers must match what the spreadsheet shows, and the millions of repeated
 * empty rows LibreOffice writes at the end of a sheet must cost nothing.
 */
final class OdsSheets
{
    private const TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    private const OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    /** A repeated row with content (rare: identical rows) is expanded at most this often. */
    private const MAX_REPEATED_ROWS = 10000;

    public function __construct(private readonly string $path) {}

    /**
     * @return list<string>
     */
    public function names(): array
    {
        $xml = $this->open();
        $names = [];

        try {
            $moved = $xml->read();

            while ($moved) {
                if ($xml->nodeType === XMLReader::ELEMENT && $this->is($xml, self::TABLE, 'table')) {
                    $names[] = (string) $xml->getAttributeNs('name', self::TABLE);
                    $moved = $xml->next();

                    continue;
                }

                $moved = $xml->read();
            }
        } finally {
            $xml->close();
        }

        return $names;
    }

    /**
     * Rows of one sheet as text, keyed by 1-based row number; empty rows are left out.
     *
     * Rows are read cell by cell, never built whole: a row may hold at most
     * XlsxSheets::MAX_COLUMNS cell elements, and with a width only the first $width
     * columns are kept (a row whose only values lie past it is yielded as an empty list,
     * so it still counts as a row).
     *
     * @return \Generator<int, list<string>>
     */
    public function rows(string $sheet, ?int $width = null): \Generator
    {
        $xml = $this->open();

        try {
            foreach ($this->rowStarts($xml, $sheet) as $first => $repeat) {
                [$cells, $filled] = $this->streamRow($xml, $width);

                if (! $filled) {
                    continue;
                }

                if ($first > XlsxSheets::MAX_ROWS) {
                    throw UnreadableSpreadsheet::because('too_large_sheet');
                }

                for ($i = 0; $i < min($repeat, self::MAX_REPEATED_ROWS); $i++) {
                    yield $first + $i => $cells;
                }
            }
        } finally {
            $xml->close();
        }
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
                $moved = $xml->read();

                while ($moved && ! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
                    if ($xml->nodeType !== XMLReader::ELEMENT) {
                        $moved = $xml->read();

                        continue;
                    }

                    if ($this->isCell($xml)) {
                        if (++$count > XlsxSheets::MAX_COLUMNS) {
                            throw UnreadableSpreadsheet::because('too_large_sheet');
                        }

                        $span = (int) ($xml->getAttributeNs('number-columns-spanned', self::TABLE) ?: 1);

                        if ($xml->localName === 'table-cell' && $span > 1) {
                            $merges[] = [$column, $column + $span - 1];
                        }

                        $column += max(1, (int) ($xml->getAttributeNs('number-columns-repeated', self::TABLE) ?: 1));
                    }

                    $moved = $xml->next();
                }

                return $merges;
            }
        } finally {
            $xml->close();
        }

        return [];
    }

    /**
     * Walks to the row elements of one sheet and yields each one's first row number and
     * repeat count while the reader stands on it. The consumer reads the row's content
     * (or not); the walk continues after the row either way.
     *
     * @return \Generator<int, int>
     */
    private function rowStarts(XMLReader $xml, string $sheet): \Generator
    {
        $inSheet = false;
        $depth = 0;
        $number = 1;
        $moved = $xml->read();

        while ($moved) {
            if ($xml->nodeType === XMLReader::END_ELEMENT && $inSheet && $xml->depth === $depth && $this->is($xml, self::TABLE, 'table')) {
                break;
            }

            if ($xml->nodeType !== XMLReader::ELEMENT) {
                $moved = $xml->read();

                continue;
            }

            if (! $inSheet && $this->is($xml, self::TABLE, 'table')) {
                if ($xml->getAttributeNs('name', self::TABLE) === $sheet) {
                    $inSheet = true;
                    $depth = $xml->depth;
                    $moved = $xml->read();
                } else {
                    $moved = $xml->next();
                }

                continue;
            }

            if (! $inSheet || ! $this->is($xml, self::TABLE, 'table-row')) {
                $moved = $xml->read();

                continue;
            }

            $repeat = max(1, (int) ($xml->getAttributeNs('number-rows-repeated', self::TABLE) ?? 1));
            $rowDepth = $xml->depth;
            $empty = $xml->isEmptyElement;

            if (! $empty) {
                yield $number => $repeat;
            }

            $number += $repeat;

            // Continue after the row: skipped whole when the consumer did not read it, or
            // from its end tag when it did.
            if ($empty || $this->standsOnStart($xml, $rowDepth)) {
                $moved = $xml->next();

                continue;
            }

            while (! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $rowDepth)) {
                if (! $xml->read()) {
                    return;
                }
            }

            $moved = $xml->read();
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
     * @return array{0: list<string>, 1: bool} the kept cells, and whether the row has any value
     */
    private function streamRow(XMLReader $xml, ?int $width): array
    {
        $depth = $xml->depth;
        $cells = [];
        $filled = false;
        $count = 0;
        $column = 0;
        $moved = $xml->read();

        while ($moved && ! ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth)) {
            if ($xml->nodeType !== XMLReader::ELEMENT) {
                $moved = $xml->read();

                continue;
            }

            if (! $this->isCell($xml)) {
                $moved = $xml->next();

                continue;
            }

            // A row can never hold more cell elements than a sheet has columns.
            if (++$count > XlsxSheets::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            $repeat = max(1, (int) ($xml->getAttributeNs('number-columns-repeated', self::TABLE) ?: 1));
            $first = $column;
            $column += $repeat;
            $outside = $width !== null && $first >= $width;

            // Covered cells are empty; past the width only whether the row has a value matters.
            if ($xml->localName === 'covered-table-cell' || ($outside && $filled)) {
                $moved = $xml->next();

                continue;
            }

            $cell = $xml->expand();
            $moved = $xml->next();
            $value = $cell instanceof DOMElement ? $this->value($cell) : '';

            if ($value === '') {
                continue;
            }

            if ($first >= XlsxSheets::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            $filled = true;

            if ($outside) {
                continue;
            }

            for ($i = count($cells); $i < $first; $i++) {
                $cells[] = '';
            }

            $last = min($first + min($repeat, 1024), $width ?? XlsxSheets::MAX_COLUMNS);

            if ($width === null && $first + min($repeat, 1024) > XlsxSheets::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            for ($i = $first; $i < $last; $i++) {
                $cells[] = $value;
            }
        }

        return [$cells, $filled];
    }

    private function value(DOMElement $cell): string
    {
        $type = $cell->getAttributeNS(self::OFFICE, 'value-type');

        if ($cell->hasAttributeNS(self::TABLE, 'formula')) {
            $shown = $this->text($cell);

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
                $raw = $cell->getAttributeNS(self::OFFICE, 'value');

                if (! is_numeric($raw)) {
                    return '';
                }

                return preg_match('/^-?\d{1,15}$/', $raw) ? (string) (int) $raw : CellValue::number((float) $raw);

            case 'boolean':
                return in_array(strtolower($cell->getAttributeNS(self::OFFICE, 'boolean-value')), ['true', '1'], true) ? '1' : '0';

            case 'date':
                $raw = $cell->getAttributeNS(self::OFFICE, 'date-value');

                if (! preg_match('/^(-?\d{4,}-\d{2}-\d{2})(?:T(\d{2}:\d{2}:\d{2}))?/', $raw, $m)) {
                    return $raw;
                }

                return (isset($m[2]) && $m[2] !== '00:00:00') ? "{$m[1]} {$m[2]}" : $m[1];

            case 'time':
                $raw = $cell->getAttributeNS(self::OFFICE, 'time-value');

                if (! preg_match('/^(-)?PT?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)(?:[.,]\d+)?S)?$/', $raw, $m)) {
                    return $raw;
                }

                return ($m[1] ?? '') . sprintf('%02d:%02d:%02d', (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (int) ($m[4] ?? 0));

            case 'string':
                if ($cell->hasAttributeNS(self::OFFICE, 'string-value')) {
                    return $cell->getAttributeNS(self::OFFICE, 'string-value');
                }

                return $this->text($cell);

            default:
                return $this->text($cell);
        }
    }

    private function text(DOMElement $cell): string
    {
        $paragraphs = [];

        foreach ($cell->childNodes as $child) {
            if ($child instanceof DOMElement && $child->namespaceURI === self::TEXT && in_array($child->localName, ['p', 'h'], true)) {
                $paragraphs[] = $this->inline($child);
            }
        }

        return implode("\n", $paragraphs);
    }

    private function inline(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $text .= $child->nodeValue;

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $text .= match ($child->namespaceURI . '#' . $child->localName) {
                self::TEXT . '#s' => str_repeat(' ', max(1, (int) ($child->getAttributeNS(self::TEXT, 'c') ?: 1))),
                self::TEXT . '#tab' => "\t",
                self::TEXT . '#line-break' => "\n",
                self::OFFICE . '#annotation', self::TEXT . '#note' => '',
                default => $this->inline($child),
            };
        }

        return $text;
    }

    private function is(XMLReader $xml, string $namespace, string $name): bool
    {
        return $xml->localName === $name && $xml->namespaceURI === $namespace;
    }

    private function open(): XMLReader
    {
        $xml = new XMLReader;

        if (! @$xml->open('zip://' . $this->path . '#content.xml', null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return $xml;
    }
}

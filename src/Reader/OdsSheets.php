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
     * @return \Generator<int, list<string>>
     */
    public function rows(string $sheet): \Generator
    {
        foreach ($this->rowElements($sheet) as $first => [$row, $repeat]) {
            $cells = $this->cells($row);

            if ($cells === []) {
                continue;
            }

            for ($i = 0; $i < min($repeat, self::MAX_REPEATED_ROWS); $i++) {
                yield $first + $i => $cells;
            }
        }
    }

    /**
     * Cells spanning several columns in one row, as 0-based [first, last] columns.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function merges(string $sheet, int $rowNumber): array
    {
        foreach ($this->rowElements($sheet) as $first => [$row, $repeat]) {
            if ($first + $repeat - 1 < $rowNumber) {
                continue;
            }

            if ($first > $rowNumber) {
                break;
            }

            $merges = [];
            $column = 0;

            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement || $cell->namespaceURI !== self::TABLE || ! in_array($cell->localName, ['table-cell', 'covered-table-cell'], true)) {
                    continue;
                }

                $span = (int) ($cell->getAttributeNS(self::TABLE, 'number-columns-spanned') ?: 1);

                if ($cell->localName === 'table-cell' && $span > 1) {
                    $merges[] = [$column, $column + $span - 1];
                }

                $column += max(1, (int) ($cell->getAttributeNS(self::TABLE, 'number-columns-repeated') ?: 1));
            }

            return $merges;
        }

        return [];
    }

    /**
     * Row elements of one sheet keyed by the number of their first row, with their repeat count.
     *
     * @return \Generator<int, array{0: DOMElement, 1: int}>
     */
    private function rowElements(string $sheet): \Generator
    {
        $xml = $this->open();
        $inSheet = false;
        $depth = 0;
        $number = 1;

        try {
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
                $node = $xml->expand();
                $moved = $xml->next();

                if ($node instanceof DOMElement) {
                    yield $number => [$node, $repeat];
                }

                $number += $repeat;
            }
        } finally {
            $xml->close();
        }
    }

    /**
     * @return list<string>
     */
    private function cells(DOMElement $row): array
    {
        $cells = [];
        $pendingEmpty = 0;

        foreach ($row->childNodes as $cell) {
            if (! $cell instanceof DOMElement || $cell->namespaceURI !== self::TABLE || ! in_array($cell->localName, ['table-cell', 'covered-table-cell'], true)) {
                continue;
            }

            $repeat = max(1, (int) ($cell->getAttributeNS(self::TABLE, 'number-columns-repeated') ?: 1));
            $value = $cell->localName === 'covered-table-cell' ? '' : $this->value($cell);

            if ($value === '') {
                $pendingEmpty += $repeat;

                continue;
            }

            for ($i = 0; $i < $pendingEmpty; $i++) {
                $cells[] = '';
            }

            $pendingEmpty = 0;

            for ($i = 0; $i < min($repeat, 1024); $i++) {
                $cells[] = $value;
            }
        }

        return $cells;
    }

    private function value(DOMElement $cell): string
    {
        $type = $cell->getAttributeNS(self::OFFICE, 'value-type');

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

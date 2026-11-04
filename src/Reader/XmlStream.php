<?php

namespace PNScripts\SheafgateLite\Reader;

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use XMLReader;

/**
 * Counts every node of untrusted XML against the request's budget, one step at a time. Nothing is expanded into a DOM and no subtree is skipped by libxml
 * where it cannot be counted (XMLReader::next(), expand() and readOuterXml() are not used).
 */
final class XmlStream
{
    /** Nodes (all types, end tags included) one cell or string item may have. */
    public const MAX_CELL_NODES = 4096;

    /** Bytes a cell's text may reach before it is refused: 4 bytes per character is UTF-8's most. */
    public const MAX_CELL_BYTES = 4 * SpreadsheetReader::MAX_CELL_LENGTH;

    /**
     * Counts the node the reader has just moved to. Callers move the reader themselves
     * ($xml->read() && XmlStream::count($xml, $budget)), so every step is visible where it
     * happens; this only counts, and always returns true.
     */
    public static function count(XMLReader $xml, Budget $budget): bool
    {
        if (++$budget->nodes > $budget->allowance) {
            throw $budget->nodesSpent();
        }

        $type = $xml->nodeType;

        if ($type === XMLReader::PI || $type === XMLReader::COMMENT) {
            $budget->markup();
        }

        return true;
    }

    /**
     * Moves the reader standing on an element to that element's end tag, counting every
     * node of its subtree (an empty element has none): what XMLReader::next() skips blindly.
     * The caller then reads on past it. Always returns true.
     */
    public static function skip(XMLReader $xml, Budget $budget): bool
    {
        if ($xml->nodeType === XMLReader::ELEMENT && ! $xml->isEmptyElement) {
            $depth = $xml->depth;

            while ($xml->read() && self::count($xml, $budget)) {
                if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) {
                    break;
                }
            }
        }

        return true;
    }

    /**
     * Whether the node the reader stands on carries text (text, CDATA or whitespace).
     */
    public static function isText(XMLReader $xml): bool
    {
        $type = $xml->nodeType;

        return $type === XMLReader::TEXT || $type === XMLReader::CDATA || $type === XMLReader::WHITESPACE || $type === XMLReader::SIGNIFICANT_WHITESPACE;
    }

    /**
     * Appends one text node to a cell's text. A text node is never longer than libxml's
     * 10 MB limit (the reader does not lift it), and the cell is refused as soon as it passes
     * MAX_CELL_BYTES, so a cell never grows past one text node plus that.
     */
    public static function append(string &$text, string $chunk): void
    {
        if (strlen($text) + strlen($chunk) > self::MAX_CELL_BYTES) {
            throw self::tooLong();
        }

        $text .= $chunk;
    }

    public static function tooLong(): UnreadableSpreadsheet
    {
        return UnreadableSpreadsheet::because('cell_too_long', replace: ['max' => number_format(SpreadsheetReader::MAX_CELL_LENGTH)]);
    }

    public static function tooComplex(): UnreadableSpreadsheet
    {
        return UnreadableSpreadsheet::because('too_complex');
    }
}

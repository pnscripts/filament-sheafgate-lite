<?php

namespace PNScripts\SheafgateLite\Reader;

use XMLReader;
use ZipArchive;

/**
 * Finds formula cells that were saved without a result (files written by libraries such
 * as openpyxl or OpenSpout are never calculated). OpenSpout reads those as 0, so they are
 * located in the sheet XML and marked instead.
 */
final class XlsxFormulas
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * Cell references ("B7") of formulas without a saved value on one sheet.
     *
     * @return array<string, true>
     */
    public static function withoutResult(string $path, string $sheet): array
    {
        $entry = self::sheetEntry($path, $sheet);

        if ($entry === null) {
            return [];
        }

        $xml = new XMLReader;

        if (! @$xml->open('zip://' . $path . '#' . $entry, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
            return [];
        }

        $missing = [];
        $cell = null;
        $hasFormula = false;
        $hasValue = false;

        try {
            while ($xml->read()) {
                if ($xml->namespaceURI !== self::MAIN) {
                    continue;
                }

                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'c') {
                    $cell = (string) $xml->getAttribute('r');
                    $hasFormula = false;
                    $hasValue = false;

                    if ($xml->isEmptyElement) {
                        $cell = null;
                    }

                    continue;
                }

                if ($cell === null) {
                    continue;
                }

                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'f') {
                    $hasFormula = true;
                } elseif ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'v') {
                    $hasValue = ! $xml->isEmptyElement && trim($xml->readString()) !== '';
                } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'c') {
                    if ($hasFormula && ! $hasValue && $cell !== '') {
                        $missing[strtoupper($cell)] = true;
                    }

                    $cell = null;
                }
            }
        } finally {
            $xml->close();
        }

        return $missing;
    }

    /**
     * The archive entry (xl/worksheets/sheet1.xml) of a sheet, by name.
     */
    private static function sheetEntry(string $path, string $sheet): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->close();

        if (! is_string($workbook) || ! is_string($rels)) {
            return null;
        }

        $relationId = null;

        if (preg_match_all('/<(?:\w+:)?sheet\b[^>]*>/', $workbook, $matches)) {
            foreach ($matches[0] as $tag) {
                if (preg_match('/\bname="([^"]*)"/', $tag, $name) && html_entity_decode($name[1], ENT_QUOTES | ENT_XML1) === $sheet
                    && preg_match('/\br:id="([^"]*)"|\b\w+:id="([^"]*)"/', $tag, $id)) {
                    $relationId = $id[1] !== '' ? $id[1] : ($id[2] ?? null);
                }
            }
        }

        if ($relationId === null || ! preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($relationId, '/') . '"[^>]*>/', $rels, $relation)
            || ! preg_match('/\bTarget="([^"]*)"/', $relation[0], $target)) {
            return null;
        }

        $target = ltrim($target[1], '/');

        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }
}

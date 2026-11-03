<?php

namespace PNScripts\SheafgateLite\Reader;

/**
 * Header row clean-up: trims names and folds line breaks, names blank cells after their
 * column letter, gives the cells of a merged header the merged name, and numbers
 * duplicates ("Size", "Size (2)") so every column can be mapped on its own.
 */
final class Headers
{
    /**
     * @param  list<string>  $cells  the header row as text
     * @param  list<array{0: int, 1: int}>  $merges  0-based [first, last] column spans on the header row
     * @return list<string>
     */
    public static function normalize(array $cells, array $merges = []): array
    {
        $cells = array_map(fn (string $cell): string => trim((string) preg_replace('/\s+/u', ' ', $cell)), $cells);

        foreach ($merges as [$first, $last]) {
            for ($column = $first + 1; $column <= $last; $column++) {
                if (array_key_exists($column, $cells) && $cells[$column] === '' && ($cells[$first] ?? '') !== '') {
                    $cells[$column] = $cells[$first];
                }
            }
        }

        $width = self::width($cells);
        $names = [];
        $seen = [];

        for ($column = 0; $column < $width; $column++) {
            $name = $cells[$column] ?? '';

            if ($name === '') {
                $name = __('sheafgate-lite::import.column', ['letter' => self::letter($column)]);
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                $number = $seen[$key];

                do {
                    $number++;
                    $candidate = "{$name} ({$number})";
                } while (isset($seen[mb_strtolower($candidate)]));

                $seen[$key] = $number;
                $name = $candidate;
                $key = mb_strtolower($name);
            }

            $seen[$key] ??= 1;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Number of columns up to the last non-blank cell.
     *
     * @param  list<string>  $cells
     */
    public static function width(array $cells): int
    {
        for ($column = count($cells) - 1; $column >= 0; $column--) {
            if (trim($cells[$column]) !== '') {
                return $column + 1;
            }
        }

        return 0;
    }

    /**
     * 0 => A, 25 => Z, 26 => AA.
     */
    public static function letter(int $column): string
    {
        $letter = '';

        for ($n = $column + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + (($n - 1) % 26)) . $letter;
        }

        return $letter;
    }

    /**
     * "AB" => 27 (0-based).
     */
    public static function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split(strtoupper($letters)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }
}

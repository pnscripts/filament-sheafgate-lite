<?php

namespace PNScripts\SheafgateLite\Reader;

/**
 * Finds the header row among the first rows of a sheet. Title rows, notes and blank
 * rows above the table are common in real spreadsheets.
 *
 * 1. If the importer's column guesses are known, the first row that matches the most
 *    importer columns wins.
 * 2. Otherwise, the first row of text cells that is about as wide as the table.
 * 3. Otherwise, the first non-empty row.
 */
final class HeaderDetector
{
    /**
     * @param  array<int, list<string>>  $rows  first non-empty rows keyed by 1-based row number
     * @param  array<string, list<string>>  $guesses  lower-case guesses per importer column
     */
    public static function detect(array $rows, array $guesses = []): ?int
    {
        if ($rows === []) {
            return null;
        }

        if ($guesses !== []) {
            $bestRow = null;
            $bestScore = 0;

            foreach ($rows as $number => $cells) {
                $values = array_map(fn (string $cell): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $cell))), $cells);
                $values = array_flip(array_filter($values, fn (string $value): bool => $value !== ''));
                $score = 0;

                foreach ($guesses as $columnGuesses) {
                    foreach ($columnGuesses as $guess) {
                        if (isset($values[$guess])) {
                            $score++;

                            break;
                        }
                    }
                }

                if ($score > $bestScore) {
                    $bestRow = $number;
                    $bestScore = $score;
                }
            }

            if ($bestRow !== null) {
                return $bestRow;
            }
        }

        $widest = max(array_map(fn (array $cells): int => self::filled($cells), $rows));

        foreach ($rows as $number => $cells) {
            $filled = self::filled($cells);

            if ($filled < 2 || $filled < $widest / 2) {
                continue;
            }

            $text = array_filter($cells, fn (string $cell): bool => trim($cell) !== '' && ! is_numeric(trim($cell)) && ! preg_match('/^\d{4}-\d{2}-\d{2}/', trim($cell)));

            if (count($text) === $filled) {
                return $number;
            }
        }

        return array_key_first($rows);
    }

    /**
     * @param  list<string>  $cells
     */
    private static function filled(array $cells): int
    {
        return count(array_filter($cells, fn (string $cell): bool => trim($cell) !== ''));
    }
}

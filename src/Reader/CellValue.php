<?php

namespace PNScripts\SheafgateLite\Reader;

use DateInterval;
use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;

/**
 * Turns a spreadsheet cell into the text Filament's importer expects, the way a person
 * reads it in the spreadsheet: dates as ISO dates, numbers without float noise or
 * exponents, booleans as 1 and 0, formulas as their saved result.
 */
final class CellValue
{
    /** Marks a formula cell without a usable saved result (never calculated, or an error). */
    public const FORMULA = "\u{FFFF}formula";

    /** Prefix marking an error cell such as #DIV/0!; the error code follows. */
    public const ERROR = "\u{FFFF}error:";

    /** Dates LibreOffice and Excel use as day zero; a value on one of them holds only a time. */
    private const TIME_ONLY_DATES = ['1899-12-30', '1899-12-31'];

    public static function toText(mixed $value): string
    {
        if ($value instanceof Cell\FormulaCell) {
            $value = $value->getComputedValue();

            // OpenSpout gives no result for formulas that were never calculated (files written
            // by libraries) or that end in an error: either way there is no value to import.
            if ($value === null) {
                return self::FORMULA;
            }
        } elseif ($value instanceof Cell\ErrorCell) {
            return self::ERROR . $value->getRawValue();
        } elseif ($value instanceof Cell) {
            $value = $value->getValue();
        }

        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            $value instanceof DateTimeInterface => self::dateTime($value),
            $value instanceof DateInterval => self::duration($value),
            default => '',
        };
    }

    /**
     * Whether a text produced by toText() stands for a cell that cannot be imported.
     */
    public static function isProblem(string $text): bool
    {
        return str_starts_with($text, "\u{FFFF}");
    }

    /**
     * The error code of an error cell (#DIV/0!), or null for a formula without a result.
     */
    public static function errorCode(string $text): ?string
    {
        return str_starts_with($text, self::ERROR) ? substr($text, strlen(self::ERROR)) : null;
    }

    /**
     * A float as a spreadsheet shows it: at most 15 significant digits, never in E notation.
     */
    public static function number(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            return '';
        }

        if ($value == floor($value) && abs($value) < 1e15) {
            $text = sprintf('%.0f', $value);

            return $text === '-0' ? '0' : $text;
        }

        $text = sprintf('%.15G', $value);

        if (! str_contains($text, 'E')) {
            return self::trimDecimals($text);
        }

        [$mantissa, $exponent] = explode('E', $text);
        $negative = str_starts_with($mantissa, '-');
        $mantissa = ltrim($mantissa, '-');
        [$whole, $fraction] = array_pad(explode('.', $mantissa), 2, '');
        $digits = $whole . $fraction;
        $point = strlen($whole) + (int) $exponent;

        if ($point <= 0) {
            $text = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $text = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $text = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }

        $text = self::trimDecimals($text);

        return ($negative && $text !== '0') ? "-{$text}" : $text;
    }

    public static function dateTime(DateTimeInterface $value): string
    {
        $date = $value->format('Y-m-d');
        $time = $value->format('H:i:s');

        if (in_array($date, self::TIME_ONLY_DATES, true) || ($date === '1904-01-01' && $time !== '00:00:00')) {
            return $time;
        }

        return $time === '00:00:00' ? $date : "{$date} {$time}";
    }

    public static function duration(DateInterval $value): string
    {
        $days = $value->days !== false ? (int) $value->days : $value->d;
        $hours = $days * 24 + $value->h;

        return ($value->invert ? '-' : '') . sprintf('%02d:%02d:%02d', $hours, $value->i, $value->s);
    }

    private static function trimDecimals(string $text): string
    {
        if (! str_contains($text, '.')) {
            return $text;
        }

        return rtrim(rtrim($text, '0'), '.');
    }
}

<?php

namespace PNScripts\SheafgateLite\Reader;

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;

/**
 * A CSV or TSV file converted to UTF-8, with its original encoding and delimiter detected.
 *
 * Encodings: a byte order mark wins (UTF-8, UTF-16 LE/BE, UTF-32 LE/BE); UTF-16 without a mark is
 * recognised by its zero bytes; valid UTF-8 stays UTF-8; anything else is single-byte
 * Windows text, told apart as Windows-1251 (Cyrillic) or Windows-1252 (Western) by how
 * many letters use the upper half of the code page. A NUL byte left after conversion
 * means the encoding was not understood, and the file is refused.
 *
 * Records are read by a small parser of its own (RFC 4180 quoting) in 64 KB chunks: a
 * field is refused once it passes the most a cell can hold, a record once it has more
 * fields than a sheet has columns, so no line, however long, is ever held whole. A line
 * break is CRLF, LF or a lone CR, the same everywhere in the file; inside quotes it is
 * part of the value.
 */
final class CsvSource
{
    public const DELIMITERS = [',', ';', "\t", '|'];

    private const SAMPLE_BYTES = 65536;

    private function __construct(
        public readonly string $path,
        public readonly string $encoding,
        public readonly string $delimiter,
        private readonly bool $temporary,
    ) {}

    public static function open(string $path, ?string $encoding = null, ?string $delimiter = null): self
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        $sample = (string) fread($handle, self::SAMPLE_BYTES);
        [$detected, $bomLength] = self::detectEncoding($sample);
        $encoding = filled($encoding) ? self::canonicalEncoding((string) $encoding) : $detected;

        if ($encoding === 'UTF-8' && $bomLength === 0 && ! self::isUtf8($sample)) {
            // Asked for UTF-8 but the bytes are not: keep going, invalid bytes become U+FFFD.
            $encoding = 'UTF-8';
        }

        $utf8Path = $path;
        $temporary = false;

        if ($encoding !== 'UTF-8' || $bomLength > 0) {
            $utf8Path = self::transcode($handle, $bomLength, $encoding);
            $temporary = true;
        }

        fclose($handle);

        $delimiter = filled($delimiter) ? (string) $delimiter : self::detectDelimiter($utf8Path);

        return new self($utf8Path, $encoding, $delimiter, $temporary);
    }

    /**
     * @return array{0: string, 1: int} the encoding and the length of its byte order mark
     */
    public static function detectEncoding(string $sample): array
    {
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return ['UTF-8', 3];
        }

        if (str_starts_with($sample, "\xFF\xFE\x00\x00")) {
            return ['UTF-32LE', 4];
        }

        if (str_starts_with($sample, "\x00\x00\xFE\xFF")) {
            return ['UTF-32BE', 4];
        }

        if (str_starts_with($sample, "\xFF\xFE")) {
            return ['UTF-16LE', 2];
        }

        if (str_starts_with($sample, "\xFE\xFF")) {
            return ['UTF-16BE', 2];
        }

        $length = strlen($sample);

        if ($length >= 4) {
            $evenZeros = 0;
            $oddZeros = 0;

            for ($i = 0; $i < min($length, 4096); $i++) {
                if ($sample[$i] === "\0") {
                    ($i % 2 === 0) ? $evenZeros++ : $oddZeros++;
                }
            }

            $pairs = intdiv(min($length, 4096), 2);

            if ($oddZeros > $pairs * 0.3 && $evenZeros < $pairs * 0.05) {
                return ['UTF-16LE', 0];
            }

            if ($evenZeros > $pairs * 0.3 && $oddZeros < $pairs * 0.05) {
                return ['UTF-16BE', 0];
            }
        }

        if (self::isUtf8($sample)) {
            return ['UTF-8', 0];
        }

        $high = 0;
        $asciiLetters = 0;

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($sample[$i]);

            if ($byte >= 0xC0) {
                $high++;
            } elseif (($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A)) {
                $asciiLetters++;
            }
        }

        // Cyrillic text in Windows-1251 is mostly bytes 0xC0-0xFF; Western text uses them for a few accents.
        return [($high > 0 && $high / max(1, $high + $asciiLetters) > 0.4) ? 'Windows-1251' : 'Windows-1252', 0];
    }

    /**
     * Guesses the delimiter from the first 64 KB (at most 25 non-blank records): the one
     * that splits the most records into the same number of fields.
     */
    public static function detectDelimiter(string $utf8Path): string
    {
        $handle = @fopen($utf8Path, 'rb');
        $sample = $handle === false ? '' : (string) fread($handle, self::SAMPLE_BYTES);
        $complete = $handle === false || feof($handle) || fread($handle, 1) === '';

        if ($handle !== false) {
            fclose($handle);
        }

        $best = ',';
        $bestScore = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $counts = [];

            try {
                foreach (self::parse(self::chunks($sample), $delimiter, PHP_INT_MAX, null, $complete) as $record) {
                    if ($record === []) {
                        continue;
                    }

                    if (count($record) > 1) {
                        $counts[] = count($record);
                    }

                    if (count($counts) >= 25) {
                        break;
                    }
                }
            } catch (UnreadableSpreadsheet) {
                // A sample that is not a table with this delimiter: it scores nothing.
            }

            if ($counts === []) {
                continue;
            }

            // The most common field count, weighted by how many records agree on it.
            $frequencies = array_count_values($counts);
            arsort($frequencies);
            $fields = (int) array_key_first($frequencies);
            $score = $frequencies[$fields] * 1000 + $fields;

            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * The records of the file, keyed by their 0-based position (blank lines included, as
     * empty lists). Only the first $keep fields of a record are kept; $onFlag hears "wide"
     * for a record with a value past them.
     *
     * @param  (\Closure(int, string): void)|null  $onFlag
     * @return \Generator<int, list<string>>
     */
    public function records(?int $keep = null, ?\Closure $onFlag = null): \Generator
    {
        $handle = @fopen($this->path, 'rb');

        if ($handle === false) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        try {
            yield from self::parse(self::fileChunks($handle), $this->delimiter, $keep ?? PHP_INT_MAX, $onFlag);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     * @return \Generator<int, string>
     */
    private static function fileChunks($handle): \Generator
    {
        while (! feof($handle)) {
            $chunk = fread($handle, self::SAMPLE_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            // A NUL byte is never text: the encoding was not understood (UTF-32 without a
            // mark, a binary file), and reading on would put NULs into names and values.
            if (str_contains($chunk, "\0")) {
                throw UnreadableSpreadsheet::because('encoding');
            }

            yield $chunk;
        }
    }

    /**
     * @return \Generator<int, string>
     */
    private static function chunks(string $text): \Generator
    {
        if (str_contains($text, "\0")) {
            throw UnreadableSpreadsheet::because('encoding');
        }

        if ($text !== '') {
            yield $text;
        }
    }

    /**
     * The parser: RFC 4180 fields (a quoted field may hold delimiters, quotes doubled and
     * line breaks), lenient about stray quotes inside an unquoted field. A line break
     * outside quotes is CRLF, LF or a lone CR. With $complete false (a sample cut short),
     * the last, possibly cut, record is left out.
     *
     * @param  iterable<int, string>  $chunks
     * @param  (\Closure(int, string): void)|null  $onFlag
     * @return \Generator<int, list<string>>
     */
    private static function parse(iterable $chunks, string $delimiter, int $keep, ?\Closure $onFlag = null, bool $complete = true): \Generator
    {
        $delimiter = $delimiter === '' ? ',' : $delimiter[0];
        $stops = $delimiter . "\"\r\n";
        $maxField = 4 * SpreadsheetReader::MAX_CELL_LENGTH;
        $index = 0;
        $fields = [];
        $count = 0;
        $bytes = 0;
        $wide = false;
        $field = '';
        $atStart = true;
        $quoted = false;
        $inQuotes = false;
        $pendingCr = false;
        $pendingQuote = false;
        $any = false;

        foreach ($chunks as $buffer) {
            $length = strlen($buffer);
            $pos = 0;

            while ($pos < $length) {
                // A CR at the end of the last chunk: a following LF belongs to the same break.
                if ($pendingCr) {
                    $pendingCr = false;

                    if ($buffer[$pos] === "\n") {
                        $pos++;

                        continue;
                    }
                }

                // A quote at the end of the last chunk, inside quotes: doubled, or the closing one.
                if ($pendingQuote) {
                    $pendingQuote = false;

                    if ($buffer[$pos] === '"') {
                        $field .= '"';
                        $pos++;

                        continue;
                    }

                    $inQuotes = false;
                }

                if ($inQuotes) {
                    $quote = strpos($buffer, '"', $pos);
                    $piece = $quote === false ? substr($buffer, $pos) : substr($buffer, $pos, $quote - $pos);

                    if (strlen($field) + strlen($piece) > $maxField) {
                        throw UnreadableSpreadsheet::because('cell_too_long', replace: ['max' => number_format(SpreadsheetReader::MAX_CELL_LENGTH)]);
                    }

                    $field .= $piece;

                    if ($quote === false) {
                        $pos = $length;

                        continue;
                    }

                    $pos = $quote + 1;

                    if ($pos >= $length) {
                        $pendingQuote = true;

                        continue;
                    }

                    if ($buffer[$pos] === '"') {
                        $field .= '"';
                        $pos++;
                    } else {
                        $inQuotes = false;
                    }

                    continue;
                }

                $span = strcspn($buffer, $stops, $pos);

                if ($span > 0) {
                    if (strlen($field) + $span > $maxField) {
                        throw UnreadableSpreadsheet::because('cell_too_long', replace: ['max' => number_format(SpreadsheetReader::MAX_CELL_LENGTH)]);
                    }

                    $field .= substr($buffer, $pos, $span);
                    $pos += $span;
                    $atStart = false;
                    $any = true;

                    continue;
                }

                $char = $buffer[$pos++];
                $any = true;

                if ($char === '"') {
                    if ($atStart) {
                        $inQuotes = true;
                        $quoted = true;
                        $atStart = false;
                    } else {
                        $field .= '"';
                    }

                    continue;
                }

                // The end of a field: kept, or past the kept columns only looked at.
                if (++$count > XlsxSheets::MAX_COLUMNS) {
                    throw UnreadableSpreadsheet::because('too_large_sheet');
                }

                if ($count <= $keep) {
                    $bytes += strlen($field);

                    if ($bytes > SpreadsheetReader::MAX_ROW_BYTES) {
                        throw UnreadableSpreadsheet::because('row_too_large', replace: ['max' => (int) (SpreadsheetReader::MAX_ROW_BYTES / 1024 / 1024)]);
                    }

                    $fields[] = $field;
                } elseif (! $wide && trim($field) !== '') {
                    $wide = true;
                }

                $field = '';
                $atStart = true;

                if ($char === $delimiter) {
                    continue;
                }

                // A line break: the record ends (a lone field-less line is a blank record).
                if ($count === 1 && ! $quoted && $fields === [''] ) {
                    $fields = [];
                }

                if ($wide && $onFlag !== null) {
                    $onFlag($index, 'wide');
                }

                yield $index++ => $fields;
                $fields = [];
                $count = 0;
                $bytes = 0;
                $wide = false;
                $quoted = false;
                $any = false;

                if ($char === "\r") {
                    if ($pos >= $length) {
                        $pendingCr = true;
                    } elseif ($buffer[$pos] === "\n") {
                        $pos++;
                    }
                }
            }
        }

        // The last record, when the file does not end with a line break.
        if ($complete && ($any || $inQuotes || $pendingQuote)) {
            if (++$count > XlsxSheets::MAX_COLUMNS) {
                throw UnreadableSpreadsheet::because('too_large_sheet');
            }

            if ($count <= $keep) {
                $fields[] = $field;
            } elseif (trim($field) !== '') {
                $wide = true;
            }

            if ($wide && $onFlag !== null) {
                $onFlag($index, 'wide');
            }

            yield $index => $fields;
        }
    }

    public static function canonicalEncoding(string $encoding): string
    {
        return match (strtolower(str_replace(['_', ' '], '-', $encoding))) {
            'utf8', 'utf-8' => 'UTF-8',
            'utf-16', 'utf-16le', 'utf16le' => 'UTF-16LE',
            'utf-16be', 'utf16be' => 'UTF-16BE',
            'utf-32', 'utf-32le', 'utf32le' => 'UTF-32LE',
            'utf-32be', 'utf32be' => 'UTF-32BE',
            'windows-1251', 'cp1251' => 'Windows-1251',
            'windows-1252', 'cp1252' => 'Windows-1252',
            'iso-8859-1', 'latin1', 'latin-1' => 'ISO-8859-1',
            default => $encoding,
        };
    }

    public function cleanUp(): void
    {
        if ($this->temporary && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function __destruct()
    {
        $this->cleanUp();
    }

    private static function isUtf8(string $sample): bool
    {
        // The sample may end inside a multibyte character: drop an incomplete tail before checking.
        for ($cut = 0; $cut < 4 && $cut < strlen($sample); $cut++) {
            if (mb_check_encoding(substr($sample, 0, strlen($sample) - $cut), 'UTF-8')) {
                return true;
            }
        }

        return $sample === '';
    }

    /**
     * @param  resource  $handle
     */
    private static function transcode($handle, int $bomLength, string $encoding): string
    {
        $target = tempnam(sys_get_temp_dir(), 'sheafgate-csv-');
        $out = $target === false ? false : fopen($target, 'wb');

        if ($target === false || $out === false) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        fseek($handle, $bomLength);

        if ($encoding !== 'UTF-8') {
            $filter = @stream_filter_append($handle, "convert.iconv.{$encoding}/UTF-8//TRANSLIT", STREAM_FILTER_READ);

            if ($filter === false) {
                fclose($out);
                @unlink($target);

                throw UnreadableSpreadsheet::because('encoding');
            }
        }

        $copied = @stream_copy_to_stream($handle, $out);
        fclose($out);

        if ($copied === false) {
            @unlink($target);

            throw UnreadableSpreadsheet::because('encoding');
        }

        return $target;
    }
}

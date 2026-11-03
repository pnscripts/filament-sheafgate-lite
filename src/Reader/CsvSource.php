<?php

namespace PNScripts\SheafgateLite\Reader;

use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;

/**
 * A CSV or TSV file converted to UTF-8, with its original encoding and delimiter detected.
 *
 * Encodings: a byte order mark wins (UTF-8, UTF-16 LE/BE); UTF-16 without a mark is
 * recognised by its zero bytes; valid UTF-8 stays UTF-8; anything else is single-byte
 * Windows text, told apart as Windows-1251 (Cyrillic) or Windows-1252 (Western) by how
 * many letters use the upper half of the code page.
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

    public static function detectDelimiter(string $utf8Path): string
    {
        $handle = fopen($utf8Path, 'rb');
        $lines = [];

        while ($handle !== false && count($lines) < 25 && ($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $lines[] = rtrim($line, "\r\n");
            }
        }

        if ($handle !== false) {
            fclose($handle);
        }

        $best = ',';
        $bestScore = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $counts = array_map(fn (string $line): int => count(str_getcsv($line, $delimiter, '"', '')), $lines);
            $counts = array_filter($counts, fn (int $count): bool => $count > 1);

            if ($counts === []) {
                continue;
            }

            // The most common field count, weighted by how many lines agree on it.
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

    public static function canonicalEncoding(string $encoding): string
    {
        return match (strtolower(str_replace(['_', ' '], '-', $encoding))) {
            'utf8', 'utf-8' => 'UTF-8',
            'utf-16', 'utf-16le', 'utf16le' => 'UTF-16LE',
            'utf-16be', 'utf16be' => 'UTF-16BE',
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

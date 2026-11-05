<?php

namespace PNScripts\SheafgateLite\Reader;

use Closure;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use Throwable;
use ZipArchive;

/**
 * Reads XLSX workbooks (XlsxSheets) and ODS workbooks (OdsSheets), both streamed by this
 * package, and CSV/TSV files (any common encoding and delimiter) as tables of text.
 */
final class SpreadsheetReader
{
    public const PREVIEW_ROWS = 30;

    public const DEFAULT_MAX_ROWS = 50000;

    public const DEFAULT_MAX_ENTRY_BYTES = 256 * 1024 * 1024;

    /** Data rows read from one sheet before reading stops with a clear error. */
    public static int $maxRows = self::DEFAULT_MAX_ROWS;

    /** Largest unpacked size of one file inside an XLSX/ODS archive. */
    public static int $maxEntryBytes = self::DEFAULT_MAX_ENTRY_BYTES;

    /** Parts above 10 MB unpacked may not be more than this many times their packed size. */
    public const MAX_RATIO = 100;

    /** The most characters a spreadsheet cell can hold in Excel and LibreOffice. */
    public const MAX_CELL_LENGTH = 32767;

    /** How many times a workbook was read row by row (for tests and diagnostics). */
    public static int $parses = 0;

    /** More sheets than any real workbook lists. */
    public const MAX_SHEETS = 1000;

    /** The preview of a sheet stops after this many rows that hold only whitespace. */
    public const PREVIEW_BLANK_ROWS = 1000;

    public const DEFAULT_MAX_ROW_ELEMENTS = 2 * XlsxSheets::MAX_ROWS;

    /**
     * Row elements (empty ones included) and other sheet elements one reader may walk in
     * all its passes together: every sheet it previews and every full read. A sheet of a
     * million empty rows, or many sheets of them, ends here instead of being walked again.
     */
    public static int $maxRowElements = self::DEFAULT_MAX_ROW_ELEMENTS;

    public const DEFAULT_MAX_CELL_ELEMENTS = 6000000;

    /**
     * Cell elements one reader may read in all its passes together: a 50,000-row sheet of
     * 80 columns (the design point) with half again as headroom. An empty cell element
     * (<c r="B2" s="1"/>, as formatted templates have them by the thousand) counts half.
     * Empty cells cost almost nothing to store, so without this a 3 MB upload of 60 million
     * <c/> elements kept PHP busy for minutes.
     */
    public static int $maxCellElements = self::DEFAULT_MAX_CELL_ELEMENTS;

    public const DEFAULT_MAX_NODES = 48000000;

    /**
     * XML nodes of every type (elements, end tags, text, whitespace, comments, processing
     * instructions) one reader may step over in all its passes together, however many values
     * they give (see Budget). A 50,000-row sheet of 80 inline-text columns is about 28 million.
     */
    public static int $maxNodes = self::DEFAULT_MAX_NODES;

    public const DEFAULT_MAX_SECONDS = 10.0;

    /**
     * The time one reader (one request) may run without values to show for it; every value
     * read adds $maxSecondsPerValue (a real sheet needs about a quarter of that). The backstop
     * for any work a node count cannot weigh. Checked about every 10 ms.
     */
    public static float $maxSeconds = self::DEFAULT_MAX_SECONDS;

    public const DEFAULT_MAX_SECONDS_PER_VALUE = 0.000008;

    public static float $maxSecondsPerValue = self::DEFAULT_MAX_SECONDS_PER_VALUE;

    public const DEFAULT_MAX_MARKUP_NODES = 10000;

    /**
     * Comments and processing instructions one reader may meet: libxml keeps every one it
     * has passed in memory until the part is closed, and real workbooks have next to none.
     */
    public static int $maxMarkupNodes = self::DEFAULT_MAX_MARKUP_NODES;

    /** The most columns a table may have: the header's real extent may not pass it. */
    public const MAX_HEADER_COLUMNS = 1024;

    /** Columns kept of a row before the header is known (one more, to see a header that is too wide). */
    public const PREVIEW_COLUMNS = self::MAX_HEADER_COLUMNS + 1;

    /** The text one row may keep; a row of longer cells is refused. */
    public const MAX_ROW_BYTES = 4 * 1024 * 1024;

    /** The text the preview keeps at most; it ends early past this. */
    public const PREVIEW_BYTES = 8 * 1024 * 1024;

    public const DEFAULT_MAX_IMPORT_BYTES = 128 * 1024 * 1024;

    /**
     * What one import may hand on to be stored, counted as the rows are stored (header names
     * and values of every row, padding included): a 50,000-row sheet of 80 columns of 30
     * characters is about 120 MB.
     */
    public static int $maxImportBytes = self::DEFAULT_MAX_IMPORT_BYTES;

    public const DEFAULT_WORKER_SECONDS = 20.0;

    /**
     * The wall clock of the reader's child process (see ReaderProcess): how long this process
     * waits for its answers, plus $maxSecondsPerValue for every value it hands back. Past it the
     * child is killed and the file refused. It bounds what no budget inside the child can see:
     * one libxml call (a start tag with 70,000 attributes) that runs for minutes.
     */
    public static float $workerSeconds = self::DEFAULT_WORKER_SECONDS;

    public const DEFAULT_WORKER_MEMORY_MB = 128;

    /** PHP's memory_limit in the child. */
    public static int $workerMemoryMb = self::DEFAULT_WORKER_MEMORY_MB;

    public const DEFAULT_WORKER_ADDRESS_SPACE_MB = 512;

    /**
     * The child's address space (RLIMIT_AS), which also bounds what libxml allocates outside
     * PHP's memory_limit. PHP itself maps about 130 MB before it reads a byte.
     */
    public static int $workerAddressSpaceMb = self::DEFAULT_WORKER_ADDRESS_SPACE_MB;

    /**
     * The PHP command-line binary that runs the child (null: found automatically; set it
     * where PHP runs under PHP-FPM and `php` is not next to it).
     */
    public static ?string $phpBinary = null;

    /** Where the cache lives when set (the child is told the parent's folder). */
    public static ?string $cacheFolder = null;

    /** The row limit of this reader (defaults to $maxRows). */
    public ?int $rowLimit = null;

    /** The most a batch of rows from the child holds before it waits for the parent. */
    private const WORKER_BATCH_BYTES = 1048576;

    /** This reader runs inside the child process and reads XML itself. */
    private bool $inWorker = false;

    /** The child process that reads this workbook's XML (XLSX/ODS only). */
    private ?ReaderProcess $process = null;

    /** @var array<string, string> sheet => its workbook part, as the child named it */
    private array $parts = [];

    /** What this reader has done so far, against its limits. */
    private ?Budget $budget = null;

    /** @var array<string, array<int, string>> per preview key: row number => "repeated" or "wide" */
    private array $flags = [];

    /** The first sheet with data, once found (false: not looked for yet). */
    private string|null|false $firstSheet = false;

    private ?CsvSource $csv = null;

    /** @var list<string>|null */
    private ?array $sheetNames = null;

    private ?XlsxSheets $xlsxSheets = null;

    /** @var array<string, array<int, list<string>>> the first rows of each sheet, read once */
    private array $previews = [];

    private function __construct(
        public readonly string $path,
        public readonly string $format,
        private readonly ?string $csvEncoding,
        private readonly ?string $csvDelimiter,
    ) {}

    /**
     * @param  string|null  $fileName  the original name, used only when the content does not tell the format
     */
    public static function open(string $path, ?string $fileName = null, ?string $csvEncoding = null, ?string $csvDelimiter = null): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        $format = self::detectFormat($path, $fileName);

        // XLSX and ODS are read only in a child process; a host without one reads CSV only.
        if ($format !== 'csv' && ! ReaderProcess::available()) {
            throw UnreadableSpreadsheet::because('reader_unavailable');
        }

        return new self($path, $format, $csvEncoding, $csvDelimiter);
    }

    public static function detectFormat(string $path, ?string $fileName = null): string
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 8);

        if ($handle !== false) {
            fclose($handle);
        }

        if (str_starts_with($magic, "PK\x03\x04")) {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw UnreadableSpreadsheet::because('unreadable');
            }

            try {
                self::assertSafeArchive($zip, $path);
            } catch (UnreadableSpreadsheet $exception) {
                $zip->close();

                throw $exception;
            }

            $mimetype = $zip->getFromName('mimetype');
            $isXlsx = $zip->locateName('xl/workbook.xml') !== false;
            $zip->close();

            return match (true) {
                $isXlsx => 'xlsx',
                is_string($mimetype) && str_starts_with(trim($mimetype), 'application/vnd.oasis.opendocument.spreadsheet') => 'ods',
                default => throw UnreadableSpreadsheet::because('not_spreadsheet'),
            };
        }

        if (str_starts_with($magic, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw UnreadableSpreadsheet::because('legacy_xls');
        }

        $extension = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'ods'], true)) {
            throw UnreadableSpreadsheet::because('unreadable');
        }

        return 'csv';
    }

    /**
     * Refuses archives that unpack to far more than they weigh (a "zip bomb") before any
     * entry is parsed: the sizes are read from the archive's directory.
     */
    /** More parts than any real workbook has; checked on the archive directory first. */
    public const MAX_ARCHIVE_ENTRIES = 5000;

    /**
     * The longest stretch of an XML part without a "<". No text node or tag of a real
     * workbook comes near it (a full cell is at most about 200 KB even with every character
     * escaped), and libxml needs about ten times a token's size while it parses one: a
     * 9.5 MB attribute alone took 110 MB. Stretches from LONG_RUN_WINDOW up are seen; from
     * twice that, always.
     */
    public const LONG_RUN_WINDOW = 512 * 1024;

    /** What the archive check notes about a checked archive, kept in a marker under this name. */
    private const MARKER = 'safe-r8';

    /**
     * @var array<string, array<string, string>> per checked archive: part => why it may not
     *                                           be read as XML ("too_complex" or "unreadable")
     */
    private static array $refusedParts = [];

    /**
     * Refuses to read a part as XML when the archive check found it unfit: a stretch too long
     * for any real token, an encoding other than UTF-8 (UTF-16 or UTF-32, NUL bytes, another
     * encoding declared) or a document type declaration. Binary parts (images) may look like
     * that; they are refused only when something points the reader at them.
     */
    public static function assertXmlPart(string $path, string $entry): void
    {
        $key = self::archiveKey($path);

        if (! isset(self::$refusedParts[$key])) {
            $marker = self::cachePath($path, self::MARKER);
            $listed = $marker !== null && is_file($marker) ? json_decode((string) file_get_contents($marker), true) : null;

            if (is_array($listed)) {
                self::$refusedParts[$key] = self::refusedFrom($listed);
            } else {
                $zip = new ZipArchive;

                if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                    throw UnreadableSpreadsheet::because('unreadable');
                }

                try {
                    self::assertSafeArchive($zip, $path);
                } finally {
                    $zip->close();
                }
            }
        }

        $reason = self::$refusedParts[$key][$entry] ?? null;

        if ($reason !== null) {
            throw UnreadableSpreadsheet::because($reason === 'unreadable' ? 'unreadable' : 'too_complex');
        }
    }

    /**
     * @param  array<mixed>  $listed
     * @return array<string, string>
     */
    private static function refusedFrom(array $listed): array
    {
        $refused = [];

        foreach ($listed as $name => $reason) {
            if (is_string($name) && is_string($reason)) {
                $refused[$name] = $reason;
            }
        }

        return $refused;
    }

    private static function archiveKey(string $path): string
    {
        $stat = @stat($path);

        return $path . '|' . ($stat === false ? '' : $stat['size'] . '|' . $stat['mtime']);
    }

    /**
     * Whether a chunk holds a stretch without "<" that covers a whole aligned window
     * (checked window by window: strcspn stops at the first "<", a few bytes in for XML).
     */
    private static function hasLongRun(string $chunk): bool
    {
        $length = strlen($chunk);

        for ($start = 0; $start + self::LONG_RUN_WINDOW <= $length; $start += self::LONG_RUN_WINDOW) {
            if (strcspn($chunk, '<', $start, self::LONG_RUN_WINDOW) === self::LONG_RUN_WINDOW) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the first bytes of a part are those of a UTF-8 XML document: no UTF-16 or
     * UTF-32 byte order mark, a "<" first (after a UTF-8 mark and whitespace; EBCDIC and
     * byte orders without a mark fail here), and no XML declaration naming another encoding.
     * Excel, LibreOffice and every library we know write OOXML and ODF parts in UTF-8; in
     * any other encoding the byte checks here (comments, processing instructions, document
     * types) would not see what libxml then parses.
     */
    private static function startsAsUtf8Xml(string $head): bool
    {
        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }

        $head = ltrim($head, " \t\r\n");

        if ($head === '' || $head[0] !== '<') {
            return false;
        }

        if (! str_starts_with($head, '<?xml')) {
            return true;
        }

        $end = strpos($head, '?>');

        if ($end === false || $end > 512) {
            return false;
        }

        $declaration = substr($head, 0, $end);

        if (! preg_match('/\sencoding\s*=\s*(["\'])([^"\']*)\1/', $declaration, $m)) {
            return true;
        }

        return in_array(strtolower($m[2]), ['utf-8', 'utf8'], true);
    }

    private static function assertSafeArchive(ZipArchive $zip, string $path): void
    {
        // An archive checked once is not unpacked again on every Livewire request.
        $marker = self::cachePath($path, self::MARKER);
        $key = self::archiveKey($path);

        if (isset(self::$refusedParts[$key])) {
            return;
        }

        if ($marker !== null && is_file($marker)) {
            $listed = json_decode((string) file_get_contents($marker), true);

            if (is_array($listed)) {
                self::$refusedParts[$key] = self::refusedFrom($listed);

                return;
            }
        }

        $refused = [];

        $tooLarge = fn (): UnreadableSpreadsheet => UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(self::$maxEntryBytes / 1024 / 1024)]);

        if ($zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw $tooLarge();
        }

        $total = 0;
        $markup = 0;
        $totalLimit = min(self::$maxEntryBytes, max(10 * 1024 * 1024, (int) filesize($path) * self::MAX_RATIO));

        // The sizes in the archive's directory can lie, so every entry is unpacked here and
        // the bytes it really produces are counted, stopping at the first limit it passes.
        // Every entry, whatever its name: a relationship may point the reader at any part
        // (a sheet named sheet1.bin, styles.dat), and entries are opened by index, so two
        // entries with the same name are both measured.
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = $stat === false ? false : $zip->getNameIndex($index);

            if ($stat === false || $name === false || str_ends_with($name, '/')) {
                continue;
            }

            $packed = max(1, (int) $stat['comp_size']);
            $limit = min(self::$maxEntryBytes, max(10 * 1024 * 1024, $packed * self::MAX_RATIO));
            $stream = $zip->getStreamIndex($index);

            if ($stream === false) {
                throw UnreadableSpreadsheet::because('unreadable');
            }

            $size = 0;
            $carry = '';
            $run = 0;
            $long = false;
            $unfit = false;
            $first = true;

            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1024 * 1024);

                    if ($chunk === false) {
                        throw UnreadableSpreadsheet::because('unreadable');
                    }

                    $size += strlen($chunk);
                    $total += strlen($chunk);

                    if ($size > $limit || $total > $totalLimit) {
                        throw $tooLarge();
                    }

                    // Not UTF-8 (a mark or declaration at the start, NUL bytes anywhere) or a
                    // document type declaration anywhere: never handed to libxml. A DTD is
                    // parsed before the first node, so no count while reading could stop it
                    // (300,000 unused entities took a minute), and no workbook part has one.
                    if (! $unfit && $chunk !== '') {
                        $unfit = ($first && ! self::startsAsUtf8Xml($chunk))
                            || str_contains($chunk, "\0")
                            || str_contains($carry . $chunk, '<!DOCTYPE');
                        $first = false;
                    }

                    // Comments and processing instructions, counted before libxml sees a
                    // byte: its XMLReader parses a run of them ahead in one step and keeps
                    // them all (a 3 MB upload reached 6 GB), so no count while reading could
                    // stop it. XML declarations (<?xml ) are not counted; the last bytes of
                    // a chunk are carried over, so a mark split between chunks is still seen
                    // and one wholly inside the carried bytes is not counted twice.
                    $buffer = $carry . $chunk;
                    $markup += substr_count($buffer, '<?') - substr_count($carry, '<?')
                        - (substr_count($buffer, '<?xml ') - substr_count($carry, '<?xml '))
                        + substr_count($buffer, '<!--') - substr_count($carry, '<!--');
                    $carry = substr($buffer, -8);

                    if ($markup > self::$maxMarkupNodes) {
                        throw UnreadableSpreadsheet::because('too_complex');
                    }

                    // The stretch without "<" running on from the previous chunk, then the
                    // windows inside this one.
                    if (! $long && $chunk !== '') {
                        $lead = strcspn($chunk, '<');
                        $run += $lead;
                        $long = $run >= self::LONG_RUN_WINDOW || self::hasLongRun($chunk);

                        if ($lead < strlen($chunk)) {
                            $run = strlen($chunk) - 1 - (int) strrpos($chunk, '<');
                        }
                    }

                    if ($chunk === '') {
                        break;
                    }
                }
            } finally {
                fclose($stream);
            }

            if ($unfit) {
                $refused[$name] = 'unreadable';
            } elseif ($long) {
                $refused[$name] = 'too_complex';
            }
        }

        self::$refusedParts[$key] = $refused;

        if ($marker !== null) {
            @file_put_contents($marker, (string) json_encode((object) $refused));
        }
    }

    /**
     * Where checked archives, sheet lists and previews of uploads are kept: the app's
     * storage/framework/cache/sheafgate (or the system temp folder outside Laravel).
     */
    public static function cacheDirectory(): string
    {
        if (self::$cacheFolder !== null && is_dir(self::$cacheFolder)) {
            return self::$cacheFolder;
        }

        $base = function_exists('storage_path') && function_exists('app') && app()->bound('path.storage')
            ? storage_path('framework/cache/sheafgate')
            : sys_get_temp_dir() . '/sheafgate-cache';

        if (! is_dir($base)) {
            @mkdir($base, 0700, true);
        }

        return $base;
    }

    /**
     * Removes everything cached for one upload (its previews hold uploaded values).
     */
    public static function forget(string $path): void
    {
        foreach (glob(self::cacheDirectory() . '/' . sha1($path) . '-*') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Removes cached entries older than the given age (default 6 hours).
     */
    public static function sweep(int $seconds = 21600): int
    {
        $removed = 0;

        foreach (glob(self::cacheDirectory() . '/*') ?: [] as $file) {
            $changed = @filemtime($file);

            if ($changed !== false && $changed <= time() - $seconds && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * A cache file tied to this upload's path, size and change time.
     */
    private static function cachePath(string $path, string $kind, string $extra = ''): ?string
    {
        static $swept = false;

        $stat = @stat($path);

        if ($stat === false) {
            return null;
        }

        $folder = self::cacheDirectory();

        if (! is_dir($folder)) {
            return null;
        }

        if (! $swept) {
            $swept = true;
            self::sweep();
        }

        return $folder . '/' . sha1($path) . '-' . sha1($stat['size'] . '|' . $stat['mtime'] . '|' . $kind . '|' . $extra);
    }

    /**
     * Sheet names in workbook order (hidden sheets left out). A CSV file has one unnamed sheet.
     *
     * @return list<string>
     */
    public function sheets(): array
    {
        if ($this->sheetNames !== null) {
            return $this->sheetNames;
        }

        if ($this->format === 'csv') {
            return $this->sheetNames = [];
        }

        $cache = self::cachePath($this->path, 'sheets');
        $cached = $cache !== null && is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;

        if (is_array($cached)) {
            return $this->sheetNames = array_values(array_map(fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $cached));
        }

        $this->sheetNames = $this->readSheetNames();

        if ($cache !== null) {
            @file_put_contents($cache, (string) json_encode($this->sheetNames, JSON_UNESCAPED_UNICODE));
        }

        return $this->sheetNames;
    }

    /**
     * @return list<string>
     */
    private function readSheetNames(): array
    {
        $process = $this->process();

        if ($process !== null) {
            $names = $process->call(['op' => 'sheets']);

            return is_array($names) ? array_values(array_map(fn (mixed $name): string => is_scalar($name) ? (string) $name : '', $names)) : [];
        }

        if ($this->format === 'ods') {
            try {
                $names = $this->withXmlErrors(fn (): array => (new OdsSheets($this->path, $this->budget()))->names());

                if (count($names) > self::MAX_SHEETS) {
                    throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(self::$maxEntryBytes / 1024 / 1024)]);
                }

                return $names;
            } catch (UnreadableSpreadsheet $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw UnreadableSpreadsheet::because('unreadable', $exception);
            }
        }

        try {
            $sheets = $this->xlsx()->sheets();
        } catch (UnreadableSpreadsheet $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        }

        if (count($sheets) > self::MAX_SHEETS) {
            throw UnreadableSpreadsheet::because('too_large', replace: ['size' => (int) round(self::$maxEntryBytes / 1024 / 1024)]);
        }

        $all = array_column($sheets, 'name');
        $visible = array_column(array_filter($sheets, fn (array $sheet): bool => $sheet['visible']), 'name');

        return $visible !== [] ? $visible : $all;
    }

    /**
     * The first sheet with at least one filled cell, or the first sheet.
     */
    public function firstSheetWithData(): ?string
    {
        if ($this->firstSheet !== false) {
            return $this->firstSheet;
        }

        $sheets = $this->sheets();

        // An ODS workbook keeps every sheet in one part: one pass finds the sheet, instead of
        // one pass per sheet that walks all the sheets before it again.
        if ($this->format === 'ods' && count($sheets) > 1) {
            $cache = self::cachePath($this->path, 'first');
            $found = $cache !== null && is_file($cache) ? (string) file_get_contents($cache) : null;

            if ($found === null) {
                $process = $this->process();
                $found = $process !== null
                    ? self::text($process->call(['op' => 'first']))
                    : (string) $this->withXmlErrors(fn (): ?string => (new OdsSheets($this->path, $this->budget()))->firstFilled(self::PREVIEW_BLANK_ROWS));

                if ($cache !== null) {
                    @file_put_contents($cache, $found);
                }
            }

            return $this->firstSheet = in_array($found, $sheets, true) ? $found : $sheets[0];
        }

        // Sheets that point at a part already looked at are not read again.
        $seen = [];

        foreach ($sheets as $sheet) {
            $key = $this->previewKey($sheet);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            if ($this->preview($sheet, 1) !== []) {
                return $this->firstSheet = $sheet;
            }
        }

        return $this->firstSheet = $sheets[0] ?? null;
    }

    /**
     * What a sheet's preview is kept under: its workbook part for XLSX, where several sheets
     * may point at the same part, otherwise its name.
     */
    private function previewKey(?string $sheet): string
    {
        $sheet = $this->resolveSheet($sheet);

        if ($this->format === 'xlsx' && $sheet !== null) {
            try {
                $process = $this->process();

                if ($process !== null) {
                    return 'part:' . ($this->parts[$sheet] ??= self::text($process->call(['op' => 'part', 'sheet' => $sheet])));
                }

                return 'part:' . $this->xlsx()->part($sheet);
            } catch (Throwable) {
                // An unknown sheet is refused when it is read.
            }
        }

        return (string) $sheet;
    }

    /**
     * The budget of this reader, made when it is first needed (the row limit may be set first).
     */
    private function budget(): Budget
    {
        return $this->budget ??= new Budget(
            maxNodes: self::$maxNodes,
            maxMarkup: self::$maxMarkupNodes,
            maxRows: self::$maxRowElements,
            maxCells: self::$maxCellElements,
            rowLimit: $this->rowLimit ?? self::$maxRows,
            seconds: self::$maxSeconds,
            secondsPerValue: self::$maxSecondsPerValue,
        );
    }

    /**
     * Runs a reader step with libxml errors collected, never shown, and any failure as a clear message.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    private function withXmlErrors(Closure $step): mixed
    {
        $previous = libxml_use_internal_errors(true);

        try {
            return $step();
        } catch (UnreadableSpreadsheet $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * The first non-empty rows of a sheet as text, keyed by row number. Each row keeps at
     * most PREVIEW_COLUMNS columns; rows that come from repeat attributes or have values
     * past those columns are noted (see flags()), so the header is never looked for there.
     *
     * @return array<int, list<string>>
     */
    public function preview(?string $sheet = null, int $limit = self::PREVIEW_ROWS): array
    {
        $key = $this->previewKey($sheet);

        if (! isset($this->previews[$key])) {
            $cache = self::cachePath($this->path, 'preview-r7', $key . '|' . $this->csvEncoding . '|' . $this->csvDelimiter);
            $cached = $cache !== null && is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;

            if (is_array($cached) && is_array($cached['rows'] ?? null) && is_array($cached['flags'] ?? null)) {
                $this->previews[$key] = [];
                $this->flags[$key] = [];

                foreach ($cached['rows'] as $number => $cells) {
                    if (is_array($cells)) {
                        $this->previews[$key][(int) $number] = array_values(array_map(fn (mixed $cell): string => is_scalar($cell) ? (string) $cell : '', $cells));
                    }
                }

                foreach ($cached['flags'] as $number => $flag) {
                    $this->flags[$key][(int) $number] = is_scalar($flag) ? (string) $flag : 'wide';
                }
            } else {
                $rows = [];
                $flags = [];
                $blank = 0;
                $bytes = 0;
                $onFlag = function (int $number, string $flag) use (&$flags): void {
                    // "wide" is the stronger note: such a row can never be the header.
                    if (($flags[$number] ?? '') !== 'wide' && count($flags) < 10 * self::PREVIEW_ROWS + self::PREVIEW_BLANK_ROWS) {
                        $flags[$number] = $flag;
                    }
                };

                // Rows come without a width (the header is not known yet), so whitespace-only
                // far cells are never padded; a sheet of such rows ends the preview early.
                foreach ($this->scan($sheet, null, null, $onFlag) as $number => $cells) {
                    // A row whose values all lie past the kept columns is not blank: it is
                    // shown (empty) and noted, so a header that wide is refused, not skipped.
                    if (implode('', array_map('trim', $cells)) === '' && ($flags[$number] ?? '') !== 'wide') {
                        if (++$blank > self::PREVIEW_BLANK_ROWS) {
                            break;
                        }

                        continue;
                    }

                    $rows[$number] = $cells;
                    $bytes += array_sum(array_map('strlen', $cells));

                    if (count($rows) >= self::PREVIEW_ROWS || $bytes > self::PREVIEW_BYTES) {
                        break;
                    }
                }

                $this->previews[$key] = $rows;
                $this->flags[$key] = array_intersect_key($flags, $rows);

                if ($cache !== null) {
                    @file_put_contents($cache, json_encode(['rows' => (object) $rows, 'flags' => (object) $this->flags[$key]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
                }
            }
        }

        /** @var array<int, list<string>> $rows */
        $rows = $this->previews[$key];

        return array_slice($rows, 0, $limit, true);
    }

    /**
     * Notes on the preview's rows: row number => "repeated" (it comes from a repeat
     * attribute) or "wide" (it has values past PREVIEW_COLUMNS).
     *
     * @return array<int, string>
     */
    public function flags(?string $sheet = null): array
    {
        $this->preview($sheet);

        return $this->flags[$this->previewKey($sheet)] ?? [];
    }

    /**
     * @param  int|null  $headerRow  1-based row number; detected when null
     * @param  array<string, list<string>>  $guesses  lower-case guesses per importer column, for header detection
     */
    public function table(?string $sheet = null, ?int $headerRow = null, array $guesses = []): Table
    {
        $sheet = $this->resolveSheet($sheet);
        $preview = $this->preview($sheet);

        if ($preview === []) {
            throw UnreadableSpreadsheet::because('empty');
        }

        $flags = $this->flags($sheet);
        $headerRow ??= HeaderDetector::detect($preview, $guesses, $flags);
        $wide = ($flags[$headerRow] ?? '') === 'wide';
        $headerCells = $preview[$headerRow] ?? $this->rowAt($sheet, (int) $headerRow, $wide);

        if ($headerCells === null || Headers::width($headerCells) === 0) {
            throw UnreadableSpreadsheet::because('no_header');
        }

        // The column budget: the header's real extent, never more than MAX_HEADER_COLUMNS.
        // Every row is read, padded and stored at this width, so a header as wide as a
        // sheet (16,384 columns) is refused here instead of multiplying every row.
        if ($wide || Headers::width($headerCells) > self::MAX_HEADER_COLUMNS) {
            throw UnreadableSpreadsheet::because('too_many_columns', replace: ['max' => number_format(self::MAX_HEADER_COLUMNS)]);
        }

        // Merged header cells show up as blanks between filled cells; without blanks there is nothing to look up.
        $width = Headers::width($headerCells);
        $hasBlanks = in_array('', array_map('trim', array_slice($headerCells, 0, $width)), true);
        $headers = Headers::normalize($headerCells, $hasBlanks ? $this->cachedHeaderMerges($sheet, (int) $headerRow) : []);
        $first = (int) $headerRow;
        $width = count($headers);

        return new Table(
            format: $this->format,
            sheet: $sheet,
            headerRow: $first,
            headers: $headers,
            cells: function () use ($sheet, $first, $width): \Generator {
                // Only the header's columns are kept: cells past them are never padded or stored.
                foreach ($this->cells($sheet, $width) as $number => $cells) {
                    if ($number > $first) {
                        yield $number => $cells;
                    }
                }
            },
            encoding: $this->csv()?->encoding,
            delimiter: $this->csv()?->delimiter,
            maxRows: $this->rowLimit ?? self::$maxRows,
            preview: array_filter($preview, fn (int $number): bool => $number > $first, ARRAY_FILTER_USE_KEY),
            maxBytes: self::$maxImportBytes,
        );
    }

    public function csvEncoding(): ?string
    {
        return $this->csv()?->encoding;
    }

    public function csvDelimiter(): ?string
    {
        return $this->csv()?->delimiter;
    }

    /**
     * All rows of a sheet as text, keyed by 1-based row number (empty rows may be left out).
     * With a width, only the first $width columns of each row are kept; a row whose values
     * all lie past them comes as an empty list, so it still counts as a row.
     *
     * @return \Generator<int, list<string>>
     */
    public function cells(?string $sheet = null, ?int $width = null): \Generator
    {
        yield from $this->scan($sheet, $width);
    }

    /**
     * @param  (Closure(int, string): void)|null  $onFlag
     * @return \Generator<int, list<string>>
     */
    private function scan(?string $sheet, ?int $width, ?int $only = null, ?Closure $onFlag = null): \Generator
    {
        self::$parses++;
        $scanned = 0;
        $maxScanned = max(100000, ($this->rowLimit ?? self::$maxRows) * 4);
        $count = function () use (&$scanned, $maxScanned): void {
            if (++$scanned > $maxScanned) {
                throw UnreadableSpreadsheet::because('too_many_rows', replace: ['max' => number_format($this->rowLimit ?? self::$maxRows)]);
            }
        };
        $previous = libxml_use_internal_errors(true);

        try {
            foreach ($this->readCells($sheet, $width, $only, $count, $onFlag) as $number => $cells) {
                // Empty rows count too: a sheet can claim a row number in the millions.
                $count();

                // CSV included: a CSV cell is no longer than a spreadsheet cell either.
                foreach ($cells as $value) {
                    if (strlen($value) > self::MAX_CELL_LENGTH && mb_strlen($value) > self::MAX_CELL_LENGTH) {
                        throw UnreadableSpreadsheet::because('cell_too_long', replace: ['max' => number_format(self::MAX_CELL_LENGTH)]);
                    }
                }

                yield $number => $cells;
            }
        } catch (UnreadableSpreadsheet $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Broken XML, a damaged archive or a reader failure: a clear message, never an error page.
            throw UnreadableSpreadsheet::because('unreadable', $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  Closure(): void  $countBlank  counts a blank CSV record toward the scanned rows
     * @param  (Closure(int, string): void)|null  $onFlag
     * @return \Generator<int, list<string>>
     */
    private function readCells(?string $sheet, ?int $width, ?int $only, Closure $countBlank, ?Closure $onFlag = null): \Generator
    {
        $csv = $this->csv();

        if ($csv !== null) {
            $flag = $onFlag === null ? null : fn (int $offset, string $note) => $onFlag($offset + 1, $note);

            // Blank lines are counted, never skipped silently: a file of millions of them
            // is refused at the scanned-row cap like any other sheet.
            foreach ($csv->records($width ?? self::PREVIEW_COLUMNS, $flag) as $offset => $record) {
                if ($record === []) {
                    $countBlank();

                    continue;
                }

                if ($only !== null && $offset + 1 !== $only) {
                    if ($offset + 1 > $only) {
                        return;
                    }

                    continue;
                }

                yield $offset + 1 => $record;
            }

            return;
        }

        $sheet = $this->resolveSheet($sheet);
        $process = $this->process();

        if ($process !== null) {
            yield from $process->rows($sheet, $width, $only, $onFlag);

            return;
        }

        if ($this->format === 'ods') {
            yield from (new OdsSheets($this->path, $this->budget()))->rows((string) $sheet, $width, $only, $onFlag);

            return;
        }

        yield from $this->xlsx()->rows((string) $sheet, $width, $only, $onFlag);
    }

    private function resolveSheet(?string $sheet): ?string
    {
        if ($this->format === 'csv') {
            return null;
        }

        if ($sheet !== null && in_array($sheet, $this->sheets(), true)) {
            return $sheet;
        }

        return $this->firstSheetWithData();
    }

    /**
     * @param  bool  $wide  set when the row has values past PREVIEW_COLUMNS
     * @return list<string>|null
     */
    private function rowAt(?string $sheet, int $number, bool &$wide = false): ?array
    {
        $onFlag = function (int $row, string $flag) use ($number, &$wide): void {
            $wide = $wide || ($row === $number && $flag === 'wide');
        };

        // Only that row is read: the rows above it are skipped unread, never padded.
        foreach ($this->scan($sheet, null, $number, $onFlag) as $index => $cells) {
            if ($index === $number) {
                return $cells;
            }

            if ($index > $number) {
                break;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function cachedHeaderMerges(?string $sheet, int $headerRow): array
    {
        $cache = self::cachePath($this->path, 'merges', $sheet . '|' . $headerRow);
        $cached = $cache !== null && is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;

        if (is_array($cached)) {
            return array_values(array_map(fn (mixed $pair): array => [is_array($pair) && is_numeric($pair[0] ?? null) ? (int) $pair[0] : 0, is_array($pair) && is_numeric($pair[1] ?? null) ? (int) $pair[1] : 0], $cached));
        }

        $merges = $this->headerMerges($sheet, $headerRow);

        if ($cache !== null) {
            @file_put_contents($cache, (string) json_encode($merges));
        }

        return $merges;
    }

    /**
     * Merged cells that span several columns of the header row.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function headerMerges(?string $sheet, int $headerRow): array
    {
        try {
            $process = $this->process();

            if ($process !== null) {
                $merges = [];

                foreach ((array) $process->call(['op' => 'merges', 'sheet' => $sheet, 'row' => $headerRow]) as $pair) {
                    if (is_array($pair) && is_int($pair[0] ?? null) && is_int($pair[1] ?? null)) {
                        $merges[] = [$pair[0], $pair[1]];
                    }
                }

                return $merges;
            }

            return match ($this->format) {
                'xlsx' => $this->xlsxMerges($sheet, $headerRow),
                'ods' => (new OdsSheets($this->path, $this->budget()))->merges((string) $sheet, $headerRow),
                default => [],
            };
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function xlsxMerges(?string $sheet, int $headerRow): array
    {
        $merges = [];

        foreach ($this->xlsx()->merges((string) $sheet) as $range) {
            if (! preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', strtoupper($range), $m)) {
                continue;
            }

            if ((int) $m[2] <= $headerRow && (int) $m[4] >= $headerRow && $m[1] !== $m[3]) {
                $merges[] = [Headers::columnIndex($m[1]), Headers::columnIndex($m[3])];
            }
        }

        return $merges;
    }

    private function csv(): ?CsvSource
    {
        if ($this->format !== 'csv') {
            return null;
        }

        return $this->csv ??= CsvSource::open($this->path, $this->csvEncoding, $this->csvDelimiter);
    }

    private function xlsx(): XlsxSheets
    {
        return $this->xlsxSheets ??= new XlsxSheets($this->path, $this->budget());
    }

    /**
     * The child process that reads this workbook (started on first use, so the row limit set
     * after open() applies); null for CSV and inside the child itself.
     */
    private function process(): ?ReaderProcess
    {
        if ($this->format === 'csv' || $this->inWorker) {
            return null;
        }

        return $this->process ??= new ReaderProcess([
            'path' => $this->path,
            'format' => $this->format,
            'row_limit' => $this->rowLimit,
            'refused' => self::$refusedParts[self::archiveKey($this->path)] ?? null,
            'cache' => self::cacheDirectory(),
            'limits' => [
                'maxRows' => self::$maxRows,
                'maxEntryBytes' => self::$maxEntryBytes,
                'maxRowElements' => self::$maxRowElements,
                'maxCellElements' => self::$maxCellElements,
                'maxNodes' => self::$maxNodes,
                'maxSeconds' => self::$maxSeconds,
                'maxSecondsPerValue' => self::$maxSecondsPerValue,
                'maxMarkupNodes' => self::$maxMarkupNodes,
                'maxImportBytes' => self::$maxImportBytes,
                'workerSeconds' => self::$workerSeconds,
                'workerAddressSpaceMb' => self::$workerAddressSpaceMb,
            ],
        ]);
    }

    /**
     * The child process ended for this reader (it ends with the reader anyway): for tests.
     */
    public function workerPid(): ?int
    {
        return $this->process?->pid();
    }

    /**
     * Ends the child process now instead of when the reader goes away.
     */
    public function close(): void
    {
        $this->process?->close();
        $this->process = null;
    }

    /**
     * @internal The child's side of ReaderProcess (reader-worker.php): one request per line
     *           on $in, answers as JSON lines on $out, until $in ends.
     *
     * @param  resource  $in
     * @param  resource  $out
     */
    public static function serve($in, $out): int
    {
        libxml_use_internal_errors(true);
        $write = function (mixed $message) use ($out): int {
            $line = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            fwrite($out, $line);

            return strlen($line);
        };
        $next = function () use ($in): ?array {
            $line = fgets($in);

            if ($line === false) {
                return null;
            }

            $request = json_decode($line, true, 8);

            return is_array($request) ? $request : [];
        };

        $write(['ready' => true]);
        fflush($out);
        $reader = null;

        while (($request = $next()) !== null) {
            $op = $request['op'] ?? null;

            try {
                if ($op === 'init') {
                    $limits = self::initWorker($request);
                    $reader = new self(self::text($request['path'] ?? null), self::text($request['format'] ?? null), null, null);
                    $reader->inWorker = true;
                    $reader->rowLimit = is_int($request['row_limit'] ?? null) ? $request['row_limit'] : null;
                    $write(['ok' => true, 'limits' => $limits]);
                } elseif ($reader === null) {
                    $write(['error' => 'unreadable']);
                } elseif ($op === 'sheets') {
                    $write(['ok' => $reader->readSheetNames()]);
                } elseif ($op === 'first') {
                    $write(['ok' => $reader->withXmlErrors(fn (): ?string => (new OdsSheets($reader->path, $reader->budget()))->firstFilled(self::PREVIEW_BLANK_ROWS))]);
                } elseif ($op === 'part') {
                    $write(['ok' => $reader->withXmlErrors(fn (): string => $reader->xlsx()->part(self::text($request['sheet'] ?? null)))]);
                } elseif ($op === 'merges') {
                    $write(['ok' => $reader->headerMerges(self::nullableText($request['sheet'] ?? null), is_int($request['row'] ?? null) ? $request['row'] : 1)]);
                } elseif ($op === 'rows') {
                    $batch = max(1, is_int($request['batch'] ?? null) ? $request['batch'] : 1);
                    $sent = 0;
                    $bytes = 0;
                    $rows = $reader->readCells(
                        self::nullableText($request['sheet'] ?? null),
                        is_int($request['width'] ?? null) ? $request['width'] : null,
                        is_int($request['only'] ?? null) ? $request['only'] : null,
                        function (): void {},
                        function (int $number, string $flag) use ($write): void {
                            $write(['flag' => [$number, $flag]]);
                        },
                    );

                    foreach ($rows as $number => $cells) {
                        $bytes += $write([$number, $cells]);

                        // A batch ends after its rows or after 1 MB, so a reader that stops
                        // early (a preview of 4 MB rows) never makes the child run far ahead.
                        if (++$sent >= $batch || $bytes >= self::WORKER_BATCH_BYTES) {
                            $write(['more' => true]);
                            fflush($out);
                            $more = $next();

                            if ($more === null) {
                                return 0;
                            }

                            if (($more['op'] ?? null) !== 'more') {
                                break;
                            }

                            $batch = max(1, is_int($more['batch'] ?? null) ? $more['batch'] : 1);
                            $sent = 0;
                            $bytes = 0;
                        }
                    }

                    // The sheet is closed (the generator's cleanup) before the answer.
                    unset($rows);
                    $write(['end' => true]);
                } else {
                    $write(['error' => 'unreadable']);
                }
            } catch (UnreadableSpreadsheet $exception) {
                $write(['error' => $exception->reason, 'replace' => (object) $exception->replace]);
            } catch (Throwable) {
                // Broken XML, a damaged archive or a reader failure: a clear message, never an error page.
                $write(['error' => 'unreadable']);
            } finally {
                libxml_clear_errors();
            }

            fflush($out);
        }

        return 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    private static function nullableText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Takes the parent's limits and caps this process (the child) before it reads a byte:
     * its address space (RLIMIT_AS) and its CPU time (RLIMIT_CPU, a backstop should the parent
     * be gone; the parent's wall clock normally ends it much sooner).
     *
     * @param  array<mixed>  $request
     * @return array{address_space: bool, cpu: bool}
     */
    private static function initWorker(array $request): array
    {
        $limits = is_array($request['limits'] ?? null) ? $request['limits'] : [];
        $int = fn (string $key, int $default): int => is_int($limits[$key] ?? null) ? $limits[$key] : $default;
        $float = fn (string $key, float $default): float => is_int($limits[$key] ?? null) || is_float($limits[$key] ?? null) ? (float) $limits[$key] : $default;

        self::$maxRows = $int('maxRows', self::$maxRows);
        self::$maxEntryBytes = $int('maxEntryBytes', self::$maxEntryBytes);
        self::$maxRowElements = $int('maxRowElements', self::$maxRowElements);
        self::$maxCellElements = $int('maxCellElements', self::$maxCellElements);
        self::$maxNodes = $int('maxNodes', self::$maxNodes);
        self::$maxSeconds = $float('maxSeconds', self::$maxSeconds);
        self::$maxSecondsPerValue = $float('maxSecondsPerValue', self::$maxSecondsPerValue);
        self::$maxMarkupNodes = $int('maxMarkupNodes', self::$maxMarkupNodes);
        self::$maxImportBytes = $int('maxImportBytes', self::$maxImportBytes);
        self::$workerSeconds = $float('workerSeconds', self::$workerSeconds);
        self::$workerAddressSpaceMb = $int('workerAddressSpaceMb', self::$workerAddressSpaceMb);

        $cache = $request['cache'] ?? null;

        if (is_string($cache) && is_dir($cache)) {
            self::$cacheFolder = $cache;
        }

        $path = self::text($request['path'] ?? null);
        $refused = $request['refused'] ?? null;

        if (is_array($refused)) {
            self::$refusedParts[self::archiveKey($path)] = self::refusedFrom($refused);
        }

        $addressSpace = false;
        $cpu = false;

        if (function_exists('posix_setrlimit') && defined('POSIX_RLIMIT_AS') && defined('POSIX_RLIMIT_CPU')) {
            $bytes = max(64, self::$workerAddressSpaceMb) * 1024 * 1024;
            $addressSpace = @posix_setrlimit(POSIX_RLIMIT_AS, $bytes, $bytes);
            $seconds = (int) ceil(self::$workerSeconds + self::$maxSecondsPerValue * self::$maxCellElements) + 10;
            $cpu = @posix_setrlimit(POSIX_RLIMIT_CPU, $seconds, $seconds + 5);
        }

        return ['address_space' => $addressSpace, 'cpu' => $cpu];
    }
}

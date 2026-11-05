<?php

namespace PNScripts\SheafgateLite\Reader;

use Closure;
use PNScripts\SheafgateLite\Exceptions\UnreadableSpreadsheet;
use RuntimeException;
use Throwable;

/**
 * The XLSX/ODS reader in a child process. The parent never parses spreadsheet XML: it
 * starts `php -d memory_limit=… reader-worker.php` with proc_open, sends one request per
 * line (JSON) and reads the answers back as JSON lines. The child limits its own address
 * space (RLIMIT_AS) and CPU time (RLIMIT_CPU) before it reads a byte of the file, and the
 * parent kills it (SIGKILL) once it has waited longer for answers than the wall clock allows:
 * SpreadsheetReader::$workerSeconds plus $maxSecondsPerValue for every value received. That
 * bounds every cost libxml can hide inside one call (a start tag with 70,000 attributes is
 * one node), which no budget inside the process can interrupt.
 *
 * Rows come in batches the parent asks for (32 first, then twice as many each time, up to
 * 2,048), so the child never runs ahead of a reader that stops early: a preview of 30 rows
 * reads 32 and the child waits. One child serves one SpreadsheetReader (one request) and keeps
 * its budget across all its passes, as the in-process reader did; it ends with the reader.
 *
 * Where proc_open is disabled, XLSX and ODS uploads are refused with a clear message
 * ("reader_unavailable") and CSV files are read as before.
 */
final class ReaderProcess
{
    /** The child's script, next to this class. */
    public const WORKER = __DIR__ . '/reader-worker.php';

    /** Rows asked for first: a preview needs 30. */
    private const FIRST_BATCH = 32;

    private const MAX_BATCH = 2048;

    /** The longest answer line accepted: a row of MAX_ROW_BYTES with every character escaped. */
    private const MAX_LINE = 4 * SpreadsheetReader::MAX_ROW_BYTES + 1048576;

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $buffer = '';

    /** How far the buffer has been searched for the end of a line. */
    private int $scanned = 0;

    private string $errors = '';

    /** Seconds spent waiting for the child's answers. */
    private float $waited = 0.0;

    /** Values received from the child (each one adds to the wall clock). */
    private int $values = 0;

    /** Set once the child was killed or failed: every later request ends with it. */
    private ?UnreadableSpreadsheet $failure = null;

    /** A row batch is open: the child waits for "more" or "stop". */
    private bool $streaming = false;

    /**
     * The limits the child could set on itself (address_space, cpu), from its answer to init.
     *
     * @var array<string, mixed>
     */
    public array $limits = [];

    /**
     * @param  array<string, mixed>  $init  what the child needs to read the file the way this process would
     */
    public function __construct(private readonly array $init) {}

    /**
     * Whether this host can run the reader in a child process: proc_open (and the calls it
     * needs) are enabled and a PHP command-line binary is known.
     */
    public static function available(): bool
    {
        foreach (['proc_open', 'proc_close', 'proc_terminate', 'proc_get_status', 'stream_select'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        $binary = self::binary();

        // A path is checked here; a bare command name is looked up when the child starts.
        if ($binary === null || (str_contains($binary, '/') && ! (is_file($binary) && is_executable($binary)))) {
            return false;
        }

        return is_file(self::WORKER);
    }

    /**
     * The PHP command-line binary: SpreadsheetReader::$phpBinary when set, otherwise this
     * process's own binary when it is the CLI or its built-in server, otherwise `php8.x` or `php` next to it (under PHP-FPM,
     * PHP_BINARY is php-fpm, which cannot run a script).
     */
    public static function binary(): ?string
    {
        $configured = SpreadsheetReader::$phpBinary;

        if ($configured !== null && $configured !== '') {
            return $configured;
        }

        // The command-line binary and its built-in server run scripts; php-fpm does not.
        if (in_array(PHP_SAPI, ['cli', 'phpdbg', 'cli-server'], true) && is_executable(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // The same PHP version first (php8.2 next to php-fpm8.2), then the default `php`.
        foreach ([PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php', '/usr/local/bin/php', '/usr/bin/php'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Sends one request and returns its answer.
     *
     * @param  array<string, mixed>  $request
     */
    public function call(array $request): mixed
    {
        $this->assertUsable();
        $this->send($request);

        $answer = $this->answer();

        if (! array_key_exists('ok', $answer)) {
            $this->fail(UnreadableSpreadsheet::because('unreadable', new RuntimeException('Unexpected answer from the reader process.')));
        }

        return $answer['ok'];
    }

    /**
     * Rows of a sheet, read by the child in batches; flags ("repeated", "wide") are passed on
     * as they come. A reader that stops early stops the child too, which then waits for the
     * next request.
     *
     * @param  (Closure(int, string): void)|null  $onFlag
     * @return \Generator<int, list<string>>
     */
    public function rows(?string $sheet, ?int $width, ?int $only, ?Closure $onFlag): \Generator
    {
        $this->assertUsable();
        $batch = self::FIRST_BATCH;
        $this->send(['op' => 'rows', 'sheet' => $sheet, 'width' => $width, 'only' => $only, 'batch' => $batch]);
        $this->streaming = true;

        try {
            while (true) {
                $line = $this->line();

                if ($line[0] === '[') {
                    $row = json_decode($line, true, 4);

                    if (! is_array($row) || ! is_int($row[0] ?? null) || ! is_array($row[1] ?? null)) {
                        $this->fail(UnreadableSpreadsheet::because('unreadable', new RuntimeException('Malformed row from the reader process.')));
                    }

                    $cells = [];

                    foreach ($row[1] as $value) {
                        $value = is_string($value) ? $value : '';
                        $cells[] = $value;

                        if ($value !== '') {
                            $this->values++;
                        }
                    }

                    yield $row[0] => $cells;

                    continue;
                }

                $message = $this->decode($line);

                if (isset($message['flag']) && is_array($message['flag']) && is_int($message['flag'][0] ?? null) && is_string($message['flag'][1] ?? null)) {
                    if ($onFlag !== null) {
                        $onFlag($message['flag'][0], $message['flag'][1]);
                    }

                    continue;
                }

                if (($message['more'] ?? false) === true) {
                    $batch = min(self::MAX_BATCH, 2 * $batch);
                    $this->send(['op' => 'more', 'batch' => $batch]);

                    continue;
                }

                if (($message['end'] ?? false) === true) {
                    $this->streaming = false;

                    return;
                }

                $this->streaming = false;
                $this->throwAnswer($message);
            }
        } finally {
            if ($this->streaming) {
                $this->stop();
            }
        }
    }

    /**
     * Ends an open row batch: the rows still on their way are dropped, then the child is told
     * to stop and close the sheet. Waiting for it counts against the wall clock like any other.
     */
    private function stop(): void
    {
        $this->streaming = false;

        if ($this->failure !== null || $this->process === null) {
            return;
        }

        try {
            while (true) {
                $line = $this->line();

                if ($line[0] === '[') {
                    continue;
                }

                $message = $this->decode($line);

                if (($message['more'] ?? false) === true) {
                    $this->send(['op' => 'stop']);

                    continue;
                }

                if (($message['end'] ?? false) === true || isset($message['error'])) {
                    return;
                }
            }
        } catch (Throwable) {
            // The child was killed (time) or failed: the reader is done either way.
        }
    }

    /**
     * Ends the child: closes its input (it exits when it reads the end), and kills it if it is
     * still running a moment later. The process is always reaped (no zombie).
     */
    public function close(): void
    {
        if ($this->process === null) {
            return;
        }

        $process = $this->process;
        $this->process = null;

        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            @fclose($this->pipes[0]);
        }

        $until = hrtime(true) + 500_000_000;

        while (($status = proc_get_status($process))['running'] && hrtime(true) < $until) {
            usleep(2000);
        }

        if ($status['running']) {
            @proc_terminate($process, 9);
        }

        foreach ($this->pipes as $index => $pipe) {
            if ($index !== 0 && is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        $this->pipes = [];
        @proc_close($process);
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * The child's process id while it runs (for tests and diagnostics).
     */
    public function pid(): ?int
    {
        if ($this->process === null) {
            return null;
        }

        return proc_get_status($this->process)['pid'];
    }

    /**
     * The seconds the child may still be waited for: the wall clock plus its value allowance.
     */
    private function remaining(): float
    {
        return SpreadsheetReader::$workerSeconds + SpreadsheetReader::$maxSecondsPerValue * $this->values - $this->waited;
    }

    private function assertUsable(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->streaming) {
            // A row stream of this reader is still open (not read to the end); it ends first.
            $this->stop();

            if ($this->failure !== null) {
                throw $this->failure;
            }
        }

        if ($this->process === null) {
            $this->start();
        }
    }

    private function start(): void
    {
        $binary = self::binary();

        if ($binary === null || ! self::available()) {
            throw UnreadableSpreadsheet::because('reader_unavailable');
        }

        $command = [
            $binary,
            '-d', 'memory_limit=' . max(16, SpreadsheetReader::$workerMemoryMb) . 'M',
            '-d', 'max_execution_time=0',
            '-d', 'display_errors=stderr',
            '-d', 'log_errors=0',
            '-d', 'html_errors=0',
            '-d', 'opcache.enable_cli=0',
            '-d', 'xdebug.mode=off',
            self::WORKER,
        ];

        try {
            $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        } catch (Throwable $exception) {
            throw UnreadableSpreadsheet::because('reader_unavailable', $exception);
        }

        if (! is_resource($process)) {
            throw UnreadableSpreadsheet::because('reader_unavailable');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        try {
            $ready = $this->decode($this->line());
        } catch (UnreadableSpreadsheet $exception) {
            // No first line: the binary is not PHP, or could not start the script.
            $this->close();

            throw $this->failure = UnreadableSpreadsheet::because('reader_unavailable', $exception);
        }

        if (($ready['ready'] ?? false) !== true) {
            $tail = $this->errorTail();
            $this->close();

            throw $this->failure = UnreadableSpreadsheet::because('reader_unavailable', new RuntimeException('The reader process did not start: ' . $tail));
        }

        $this->send(['op' => 'init', ...$this->init]);
        $answer = $this->answer();

        if (($answer['ok'] ?? null) !== true) {
            $this->fail(UnreadableSpreadsheet::because('unreadable', new RuntimeException('The reader process refused its settings.')));
        }

        $limits = $answer['limits'] ?? null;
        $this->limits = is_array($limits) ? array_filter($limits, fn (mixed $key): bool => is_string($key), ARRAY_FILTER_USE_KEY) : [];
    }

    /**
     * @param  array<string, mixed>  $request
     */
    private function send(array $request): void
    {
        $line = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
        $pipe = $this->pipes[0] ?? null;

        if (! is_resource($pipe) || @fwrite($pipe, $line) !== strlen($line)) {
            $this->fail(UnreadableSpreadsheet::because('reader_failed', new RuntimeException('The reader process ended: ' . $this->errorTail())));
        }

        @fflush($pipe);
    }

    /**
     * The next answer that is not a row: errors become exceptions.
     *
     * @return array<string, mixed>
     */
    private function answer(): array
    {
        $message = $this->decode($this->line());

        if (isset($message['error'])) {
            $this->throwAnswer($message);
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function throwAnswer(array $message): never
    {
        $reason = is_string($message['error'] ?? null) ? $message['error'] : 'unreadable';
        $replace = [];

        foreach (is_array($message['replace'] ?? null) ? $message['replace'] : [] as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value))) {
                $replace[$key] = $value;
            }
        }

        // A refusal ends this request, not the child: the reader's later passes see it again.
        throw UnreadableSpreadsheet::because($reason, replace: $replace);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $line): array
    {
        $message = json_decode($line, true, 8);

        if (! is_array($message)) {
            $this->fail(UnreadableSpreadsheet::because('unreadable', new RuntimeException('Malformed answer from the reader process.')));
        }

        /** @var array<string, mixed> $message */
        return $message;
    }

    /**
     * The next line from the child, waiting no longer than the wall clock allows: past it the
     * child is killed and the file refused as too complex.
     */
    private function line(): string
    {
        while (true) {
            // Only the bytes not searched yet: a 4 MB row arrives in 64 KB reads.
            $end = strpos($this->buffer, "\n", $this->scanned);

            if ($end === false) {
                $this->scanned = strlen($this->buffer);
            } else {
                $line = substr($this->buffer, 0, $end);
                $this->buffer = substr($this->buffer, $end + 1);
                $this->scanned = 0;

                if ($line !== '') {
                    return $line;
                }

                continue;
            }

            if (strlen($this->buffer) > self::MAX_LINE) {
                $this->fail(UnreadableSpreadsheet::because('row_too_large', replace: ['max' => (int) (SpreadsheetReader::MAX_ROW_BYTES / 1024 / 1024)]));
            }

            $remaining = $this->remaining();

            if ($remaining <= 0) {
                $this->fail(UnreadableSpreadsheet::because('too_complex', new RuntimeException(sprintf('The reader process ran past its wall clock (%.1f s) and was stopped.', $this->waited))));
            }

            $out = $this->pipes[1] ?? null;
            $err = $this->pipes[2] ?? null;

            if (! is_resource($out)) {
                $this->fail(UnreadableSpreadsheet::because('reader_failed'));
            }

            $read = is_resource($err) ? [$out, $err] : [$out];
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            $started = hrtime(true);
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1e6));
            $this->waited += (hrtime(true) - $started) / 1e9;

            if ($ready === false) {
                // Interrupted by a signal: wait again with what is left.
                continue;
            }

            if (is_resource($err) && in_array($err, $read, true)) {
                $chunk = (string) @fread($err, 65536);
                $this->errors = substr($this->errors . $chunk, -4096);
            }

            if (in_array($out, $read, true)) {
                $chunk = @fread($out, 1048576);

                if ($chunk === false || ($chunk === '' && feof($out))) {
                    $this->fail(UnreadableSpreadsheet::because('reader_failed', new RuntimeException('The reader process ended: ' . $this->errorTail())));
                }

                $this->buffer .= $chunk;
            }
        }
    }

    /**
     * Kills the child, keeps the failure for every later request and throws it.
     */
    private function fail(UnreadableSpreadsheet $failure): never
    {
        if ($this->process !== null) {
            @proc_terminate($this->process, 9);
            $this->close();
        }

        $this->buffer = '';
        $this->scanned = 0;
        $this->streaming = false;
        $this->failure = $failure;

        throw $failure;
    }

    private function errorTail(): string
    {
        $err = $this->pipes[2] ?? null;

        if (is_resource($err)) {
            $this->errors = substr($this->errors . (string) @stream_get_contents($err, 4096), -4096);
        }

        return trim($this->errors) === '' ? 'no output' : trim($this->errors);
    }
}

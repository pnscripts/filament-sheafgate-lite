<?php

namespace PNScripts\SheafgateLite\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The uploaded file could not be read as XLSX, ODS or CSV. The message is translated and safe to show.
 */
class UnreadableSpreadsheet extends RuntimeException
{
    /** Why (a key of the import.errors translations). */
    public string $reason = 'unreadable';

    /** @var array<string, string|int> the values the message names */
    public array $replace = [];

    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $reason, ?Throwable $previous = null, array $replace = []): self
    {
        // The reader's child process runs without Laravel: it sends the reason, not the text.
        $message = function_exists('__') ? __("sheafgate-lite::import.errors.{$reason}", $replace) : $reason;

        $exception = new self(is_string($message) ? $message : $reason, 0, $previous);
        $exception->reason = $reason;
        $exception->replace = $replace;

        return $exception;
    }
}

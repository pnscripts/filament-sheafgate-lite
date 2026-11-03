<?php

namespace PNScripts\SheafgateLite\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The uploaded file could not be read as XLSX, ODS or CSV. The message is translated and safe to show.
 */
class UnreadableSpreadsheet extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $reason, ?Throwable $previous = null, array $replace = []): self
    {
        $message = __("sheafgate-lite::import.errors.{$reason}", $replace);

        return new self(is_string($message) ? $message : $reason, 0, $previous);
    }
}

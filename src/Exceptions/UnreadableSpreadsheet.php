<?php

namespace PNScripts\SheafgateLite\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The uploaded file could not be read as XLSX, ODS or CSV. The message is translated and safe to show.
 */
class UnreadableSpreadsheet extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self(__("sheafgate-lite::import.errors.{$reason}"), 0, $previous);
    }
}

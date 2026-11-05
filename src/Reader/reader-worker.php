<?php

/*
 * The XLSX/ODS reader's child process (see ReaderProcess): requests as JSON lines on
 * standard input, answers as JSON lines on standard output. It loads only this package's
 * reader classes, never the application.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'PNScripts\\SheafgateLite\\';

    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

exit(PNScripts\SheafgateLite\Reader\SpreadsheetReader::serve(STDIN, STDOUT));

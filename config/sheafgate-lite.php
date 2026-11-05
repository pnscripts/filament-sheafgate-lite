<?php

return [

    /*
    | XLSX and ODS files are read in a separate PHP process, never in the web request: the
    | request waits for it at most `timeout` seconds (plus a little per value read) and then
    | stops it; the process may use `memory_mb` of PHP memory and `address_space_mb` of
    | address space in all. `php_binary` is the PHP command-line binary that runs it (found
    | automatically; set it when PHP runs as PHP-FPM and no `php` is next to it). Where PHP
    | may not start processes (proc_open disabled), XLSX and ODS uploads are refused with a
    | clear message and CSV files still work.
    */
    'reader' => [
        'php_binary' => env('SHEAFGATE_PHP_BINARY'),
        'timeout' => 20,
        'memory_mb' => 128,
        'address_space_mb' => 512,
    ],

];

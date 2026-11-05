<?php

namespace PNScripts\SheafgateLite;

use Illuminate\Support\ServiceProvider;
use PNScripts\SheafgateLite\Reader\SpreadsheetReader;

class SheafgateLiteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sheafgate-lite.php', 'sheafgate-lite');
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'sheafgate-lite');

        $timeout = config('sheafgate-lite.reader.timeout');
        $memory = config('sheafgate-lite.reader.memory_mb');
        $addressSpace = config('sheafgate-lite.reader.address_space_mb');
        $binary = config('sheafgate-lite.reader.php_binary');

        SpreadsheetReader::$workerSeconds = is_numeric($timeout) && $timeout > 0 ? (float) $timeout : SpreadsheetReader::DEFAULT_WORKER_SECONDS;
        SpreadsheetReader::$workerMemoryMb = max(32, is_numeric($memory) ? (int) $memory : SpreadsheetReader::DEFAULT_WORKER_MEMORY_MB);
        SpreadsheetReader::$workerAddressSpaceMb = max(256, is_numeric($addressSpace) ? (int) $addressSpace : SpreadsheetReader::DEFAULT_WORKER_ADDRESS_SPACE_MB);
        SpreadsheetReader::$phpBinary = is_string($binary) && $binary !== '' ? $binary : null;

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sheafgate-lite.php' => config_path('sheafgate-lite.php'),
            ], 'sheafgate-lite-config');

            $this->publishes([
                __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/sheafgate-lite'),
            ], 'sheafgate-lite-translations');
        }
    }
}

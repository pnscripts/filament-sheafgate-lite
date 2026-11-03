<?php

namespace PNScripts\SheafgateLite;

use Illuminate\Support\ServiceProvider;

class SheafgateLiteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'sheafgate-lite');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/lang' => $this->app->langPath('vendor/sheafgate-lite'),
            ], 'sheafgate-lite-translations');
        }
    }
}

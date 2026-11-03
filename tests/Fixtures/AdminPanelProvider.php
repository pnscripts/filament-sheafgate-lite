<?php

namespace PNScripts\SheafgateLite\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use PNScripts\SheafgateLite\Tests\Fixtures\Resources\ProductResource;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([ProductResource::class]);
    }
}

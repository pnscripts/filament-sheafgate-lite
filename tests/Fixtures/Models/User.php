<?php

namespace PNScripts\SheafgateLite\Tests\Fixtures\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['can_import' => 'boolean'];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}

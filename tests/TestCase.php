<?php

namespace PNScripts\SheafgateLite\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PNScripts\SheafgateLite\SheafgateLiteServiceProvider;
use PNScripts\SheafgateLite\Tests\Fixtures\AdminPanelProvider;
use PNScripts\SheafgateLite\Tests\Fixtures\Models\Product;
use PNScripts\SheafgateLite\Tests\Fixtures\Models\User;
use PNScripts\SheafgateLite\Tests\Fixtures\Policies\ProductPolicy;
use PNScripts\SheafgateLite\Tests\Fixtures\Resources\ProductResource;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        ProductResource::$configureImport = null;
        Gate::policy(Product::class, ProductPolicy::class);

        $this->user = User::query()->create([
            'name' => 'Editor',
            'email' => 'editor@example.test',
            'password' => 'not-used',
            'can_import' => true,
        ]);

        $this->actingAs($this->user);
    }

    protected function getPackageProviders($app): array
    {
        return [
            ActionsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeIconsServiceProvider::class,
            FilamentServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            LivewireServiceProvider::class,
            NotificationsServiceProvider::class,
            SchemasServiceProvider::class,
            SupportServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            AdminPanelProvider::class,
            SheafgateLiteServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('s', 32)));
        $app['config']->set('auth.providers.users.model', User::class);
        // Filament's Import model looks up the user model through this binding (5.8 has no other fallback in a package).
        $app->bind(\Illuminate\Contracts\Auth\Authenticatable::class, User::class);
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('queue.batching.database', 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}

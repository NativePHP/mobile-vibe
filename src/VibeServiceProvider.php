<?php

namespace Nativephp\Vibe;

use Illuminate\Support\ServiceProvider;

class VibeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Users configure the connection with the standard PUSHER_* vars in
        // their app's .env; merging here means config('vibe.connection') works
        // without publishing anything.
        $this->mergeConfigFrom(__DIR__.'/../config/vibe.php', 'vibe');

        $this->app->singleton(Vibe::class, fn () => new Vibe);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/vibe.php' => config_path('vibe.php'),
            ], 'vibe-config');
        }
    }
}

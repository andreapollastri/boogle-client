<?php

namespace Boogle;

use Boogle\Commands\BoogleTestCommand;
use Boogle\Commands\BoogleInstallCommand;
use Illuminate\Support\ServiceProvider;

class BoogleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/boogle.php', 'boogle');

        $this->app->singleton(Boogle::class, fn() => new Boogle());
        $this->app->alias(Boogle::class, 'boogle');
    }

    public function boot(): void
    {
        $this->commands([
            BoogleTestCommand::class,
            BoogleInstallCommand::class,
        ]);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/boogle.php' => config_path('boogle.php'),
            ], 'boogle-config');
        }
    }
}

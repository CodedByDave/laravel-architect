<?php

namespace YourName\Architect;

use Illuminate\Support\ServiceProvider;
use YourName\Architect\Commands\MakeServiceCommand;

class ArchitectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/architect.php', 'architect');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeServiceCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/architect.php' => config_path('architect.php'),
            ], 'architect-config');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/architect'),
            ], 'architect-stubs');
        }
    }
}

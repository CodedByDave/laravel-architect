<?php

namespace LaravelArchitect\Architect;

use Illuminate\Support\ServiceProvider;
use LaravelArchitect\Architect\Commands\MakeServiceCommand;
use LaravelArchitect\Architect\Commands\MakeRepositoryCommand;

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
                MakeRepositoryCommand::class,
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

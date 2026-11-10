<?php

namespace LaravelArchitect\Architect\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use LaravelArchitect\Architect\ArchitectServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ArchitectServiceProvider::class];
    }
}

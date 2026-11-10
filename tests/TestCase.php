<?php

namespace YourName\Architect\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use YourName\Architect\ArchitectServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ArchitectServiceProvider::class];
    }
}

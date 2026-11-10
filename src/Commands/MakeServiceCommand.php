<?php

namespace YourName\Architect\Commands;

use Illuminate\Console\GeneratorCommand;

class MakeServiceCommand extends GeneratorCommand
{
    protected $name = 'make:service';

    protected $description = 'Create a new service class';

    protected $type = 'Service';

    protected function getStub(): string
    {
        // Prefer a published stub if the developer customized it.
        $published = base_path('stubs/architect/service.stub');

        return file_exists($published)
            ? $published
            : __DIR__.'/../../stubs/service.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Services';
    }
}

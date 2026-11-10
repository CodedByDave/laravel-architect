<?php

namespace LaravelArchitect\Architect\Commands;
use Illuminate\Console\GeneratorCommand;

class MakeRepositoryCommand extends GeneratorCommand
{
    protected $name = 'make:repository';

    protected $description = 'Create a new repository class';

    protected $type = 'Repository';

    protected function getStub(): string
    {
        // Prefer a published stub if the developer customized it.
        $published = base_path('stubs/architect/repository.stub');

        return file_exists($published)
            ? $published
            : __DIR__.'/../../stubs/repository.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Repositories';
    }
}

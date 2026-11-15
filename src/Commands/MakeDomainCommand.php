<?php

namespace LaravelArchitect\Architect\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeDomainCommand extends Command
{
    protected $signature = 'make:domain
                            {name : Domain name, e.g. Customer}
                            {--service : Create only the service}
                            {--repository : Create only the repository}
                            {--modular : Group by domain instead, e.g. app/Customer/Services and app/Customer/Repositories}
                            {--force : Overwrite existing files}';

    protected $description = 'Create a service and repository for a domain (app/Services/{Name}, app/Repositories/{Name})';

    public function handle(): int
    {
        $domain = Str::studly((string) $this->argument('name'));

        if ($domain === '' || ! preg_match('/^[A-Z][A-Za-z0-9]*$/', $domain)) {
            $this->components->error('Domain name must be letters/numbers only, e.g. Customer.');

            return self::FAILURE;
        }

        // No flag = both. One flag = just that one.
        $both = ! $this->option('service') && ! $this->option('repository');
        $namespace = trim($this->laravel->getNamespace(), '\\');
        $status = self::SUCCESS;

        $targets = [];
        if ($both || $this->option('service')) {
            $targets['make:service'] = $this->classFor($namespace, $domain, 'Services', 'Service');
        }
        if ($both || $this->option('repository')) {
            $targets['make:repository'] = $this->classFor($namespace, $domain, 'Repositories', 'Repository');
        }

        foreach ($targets as $command => $class) {
            $status = max($status, $this->call($command, [
                'name' => $class,
                '--force' => (bool) $this->option('force'),
            ]));
        }

        return $status;
    }

    private function classFor(string $namespace, string $domain, string $layer, string $suffix): string
    {
        $parts = $this->option('modular')
            ? [$namespace, $domain, $layer, $domain.$suffix]
            : [$namespace, $layer, $domain, $domain.$suffix];

        return implode('\\', $parts);
    }
}

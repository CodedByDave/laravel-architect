<?php

namespace LaravelArchitect\Architect\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    private const PARTS = ['model', 'migration', 'requests', 'service', 'repository', 'controller', 'routes', 'test', 'views'];

    protected $signature = 'make:module
                            {name : Module name, e.g. Customer}
                            {--except=* : Skip parts: model, migration, requests, service, repository, controller, routes, test, views (repeatable)}
                            {--api : Generate an API controller and routes/api.php routes instead of web}
                            {--modular : Put the service and repository under app/Customer/ instead of the layer folders}
                            {--force : Overwrite existing files}';

    protected $description = 'Scaffold a full module: model, migration, form requests, service, repository, controller, routes, feature test and blade views';

    public function handle(Filesystem $files): int
    {
        $name = Str::studly((string) $this->argument('name'));

        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            $this->components->error('Module name must be letters/numbers only, e.g. Customer.');

            return self::FAILURE;
        }

        $except = array_map('strtolower', (array) $this->option('except'));
        $unknown = array_diff($except, self::PARTS);

        if ($unknown !== []) {
            $this->components->error('Unknown part(s): '.implode(', ', $unknown).'. Choose from: '.implode(', ', self::PARTS).'.');

            return self::FAILURE;
        }

        $wants = fn (string $part): bool => ! in_array($part, $except, true);
        $force = (bool) $this->option('force');
        $namespace = trim($this->laravel->getNamespace(), '\\');
        $status = self::SUCCESS;

        if ($wants('model')) {
            $status = max($status, $this->call('make:model', array_filter([
                'name' => $name,
                '--migration' => $wants('migration'),
                '--force' => $force,
            ])));
        } elseif ($wants('migration')) {
            $status = max($status, $this->call('make:migration', [
                'name' => 'create_'.Str::snake(Str::pluralStudly($name)).'_table',
                '--create' => Str::snake(Str::pluralStudly($name)),
            ]));
        }

        if ($wants('requests')) {
            foreach (['Store', 'Update'] as $prefix) {
                $status = max($status, $this->call('make:request', array_filter([
                    'name' => "{$name}/{$prefix}{$name}Request",
                    '--force' => $force,
                ])));
            }
        }

        if ($wants('service')) {
            $status = max($status, $this->call('make:service', [
                'name' => $this->classFor($namespace, $name, 'Services', 'Service'),
                '--force' => $force,
            ]));
        }

        if ($wants('repository')) {
            $status = max($status, $this->call('make:repository', [
                'name' => $this->classFor($namespace, $name, 'Repositories', 'Repository'),
                '--force' => $force,
            ]));
        }

        $api = (bool) $this->option('api');
        // Separate concerns: Web/ and Api/ controllers, then one folder per domain.
        $controller = ($api ? 'Api' : 'Web')."/{$name}/{$name}Controller";

        if ($wants('controller')) {
            $status = max($status, $this->call('make:controller', array_filter([
                'name' => $controller,
                $api ? '--api' : '--resource' => true,
                '--force' => $force,
            ])));
        }

        if ($wants('routes')) {
            $this->addRoutes($files, $name, $namespace, $controller, $api);
        }

        if ($wants('test')) {
            $status = max($status, $this->call('make:test', array_filter([
                'name' => "{$name}/{$name}Test",
                '--pest' => class_exists(\Pest\TestSuite::class),
                '--force' => $force,
            ])));
        }

        if ($wants('views') && ! $api) {
            $this->makeViews($files, $name, $force);
        }

        return $status;
    }

    private function addRoutes(Filesystem $files, string $name, string $namespace, string $controller, bool $api): void
    {
        $relative = $api ? 'routes/api.php' : 'routes/web.php';
        $path = base_path($relative);
        $uri = Str::kebab(Str::pluralStudly($name));
        $fqcn = $namespace.'\\Http\\Controllers\\'.str_replace('/', '\\', $controller);
        $short = class_basename($fqcn);
        $param = Str::camel($name);

        $existing = $files->exists($path) ? $files->get($path) : "<?php\n";

        if (str_contains($existing, "->name('{$uri}.')")) {
            $this->components->warn("Route for [{$uri}] already exists in {$relative}.");

            return;
        }

        $content = $this->addImport($existing, 'Illuminate\\Support\\Facades\\Route');

        // Use the short name unless another class with the same short name is already imported.
        $clash = preg_match('/^use\s+(?!'.preg_quote($fqcn, '/').';)[^;]*\\\\'.preg_quote($short, '/').';/m', $content) === 1;
        $reference = $clash ? '\\'.$fqcn : $short;

        if (! $clash) {
            $content = $this->addImport($content, $fqcn);
        }

        $routes = [
            ['get', '/', 'index', 'index'],
            ['get', '/create', 'create', 'create'],
            ['post', '/', 'store', 'store'],
            ['get', '/{'.$param.'}', 'show', 'show'],
            ['get', '/{'.$param.'}/edit', 'edit', 'edit'],
            ['put', '/{'.$param.'}', 'update', 'update'],
            ['patch', '/{'.$param.'}', 'update', null],
            ['delete', '/{'.$param.'}', 'destroy', 'destroy'],
        ];

        $lines = ["Route::prefix('{$uri}')->name('{$uri}.')->controller({$reference}::class)->group(function () {"];

        foreach ($routes as [$verb, $segment, $action, $routeName]) {
            // APIs have no HTML form pages.
            if ($api && in_array($action, ['create', 'edit'], true)) {
                continue;
            }

            $lines[] = "    Route::{$verb}('{$segment}', '{$action}')".($routeName !== null ? "->name('{$routeName}')" : '').';';
        }

        $lines[] = '});';
        $line = implode("\n", $lines);

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, rtrim($content)."\n\n".$line."\n");
        $this->components->info("Routes added to [{$relative}] for {$reference}.");

        $bootstrap = base_path('bootstrap/app.php');

        if ($api && $files->exists($bootstrap) && ! str_contains($files->get($bootstrap), 'api:')) {
            $this->components->warn('routes/api.php is not registered in bootstrap/app.php. Run `php artisan install:api` to enable API routing.');
        }
    }

    /** Add `use Foo\Bar;` after the last existing import (or after the opening tag), unless present. */
    private function addImport(string $code, string $fqcn): string
    {
        $statement = 'use '.$fqcn.';';

        if (preg_match('/^'.preg_quote($statement, '/').'$/m', $code)) {
            return $code;
        }

        if (preg_match_all('/^use\s[^;]+;[ \t]*$/m', $code, $matches, PREG_OFFSET_CAPTURE) && $matches[0] !== []) {
            [$last, $offset] = end($matches[0]);
            $position = $offset + strlen($last);

            return substr($code, 0, $position)."\n".$statement.substr($code, $position);
        }

        return preg_replace('/^<\?php\s*/', "<?php\n\n".$statement."\n\n", $code, 1) ?? $code;
    }

    private function makeViews(Filesystem $files, string $name, bool $force): void
    {
        $folder = Str::kebab(Str::pluralStudly($name));
        $plural = Str::headline(Str::pluralStudly($name));
        $singular = Str::headline($name);

        $pages = [
            'index' => "<h1>{$plural}</h1>\n\n<a href=\"#\">New {$singular}</a>\n",
            'create' => "<h1>New {$singular}</h1>\n\n<form method=\"POST\" action=\"#\">\n    @csrf\n    <button type=\"submit\">Save</button>\n</form>\n",
            'edit' => "<h1>Edit {$singular}</h1>\n\n<form method=\"POST\" action=\"#\">\n    @csrf\n    @method('PUT')\n    <button type=\"submit\">Update</button>\n</form>\n",
            'show' => "<h1>{$singular}</h1>\n",
        ];

        foreach ($pages as $page => $content) {
            $path = resource_path("views/{$folder}/{$page}.blade.php");

            if ($files->exists($path) && ! $force) {
                $this->components->error("View [resources/views/{$folder}/{$page}.blade.php] already exists.");

                continue;
            }

            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $content);
            $this->components->info("View [resources/views/{$folder}/{$page}.blade.php] created successfully.");
        }
    }

    private function classFor(string $namespace, string $domain, string $layer, string $suffix): string
    {
        $parts = $this->option('modular')
            ? [$namespace, $domain, $layer, $domain.$suffix]
            : [$namespace, $layer, $domain, $domain.$suffix];

        return implode('\\', $parts);
    }
}

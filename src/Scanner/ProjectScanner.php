<?php

namespace LaravelArchitect\Architect\Scanner;

use FilesystemIterator;
use PhpParser\Error as ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ProjectScanner
{
    private const LAYER_DIRECTORIES = [
        'services' => 'app/Services',
        'repositories' => 'app/Repositories',
        'enums' => 'app/Enums',
        'dtos' => 'app/DTOs',
        'actions' => 'app/Actions',
        'policies' => 'app/Policies',
        'resources' => 'app/Http/Resources',
    ];

    private string $basePath;

    private CodeAnalyzer $analyzer;

    public function __construct(string $basePath, ?CodeAnalyzer $analyzer = null)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $this->analyzer = $analyzer ?? new CodeAnalyzer();
    }

    public function scan(array $routes = []): array
    {
        $models = $this->scanModels();
        $controllers = $this->scanControllers(array_column($models, 'class'));
        $routeSummary = $this->summarizeRoutes($routes);
        $formRequests = $this->files('app/Http/Requests');

        return [
            'counts' => [
                'controllers' => count($controllers),
                'models' => count($models),
                'form_requests' => count($formRequests),
                'routes' => $routeSummary['total'],
                'api_routes' => $routeSummary['api'],
                'web_routes' => $routeSummary['web'],
                'closure_routes' => $routeSummary['closure'],
            ],
            'layers' => $this->scanLayers(),
            'controllers' => $controllers,
            'models' => $models,
            'frontend' => $this->scanFrontend(),
        ];
    }

    private function scanModels(): array
    {
        $models = [];

        foreach ($this->files('app/Models') as $file) {
            try {
                $info = $this->analyzer->analyzeModel((string) file_get_contents($file));
            } catch (ParseError) {
                continue;
            }

            if ($info['class'] === null) {
                continue;
            }

            $models[] = [
                'class' => $info['class'],
                'file' => $this->relative($file),
                'status_constants' => $info['status_constants'],
            ];
        }

        return $models;
    }

    private function scanControllers(array $modelClasses): array
    {
        $controllers = [];

        foreach ($this->files('app/Http/Controllers') as $file) {
            $relative = $this->relative($file);

            if ($relative === 'app/Http/Controllers/Controller.php') {
                continue;
            }

            try {
                $analysis = $this->analyzer->analyzeController((string) file_get_contents($file), $modelClasses);
            } catch (ParseError $error) {
                $controllers[] = ['file' => $relative, 'parse_error' => $error->getMessage()];

                continue;
            }

            if ($analysis['class'] === null) {
                continue;
            }

            $kind = match (true) {
                $analysis['returns_json'] && $analysis['returns_view'] => 'mixed',
                $analysis['returns_json'] => 'api',
                $analysis['returns_view'] => 'web',
                default => 'unknown',
            };

            $controllers[] = $analysis + [
                'file' => $relative,
                'kind' => $kind,
                'in_api_folder' => str_contains('/'.$relative, '/Api/'),
                'in_web_folder' => str_contains('/'.$relative, '/Web/'),
            ];
        }

        return $controllers;
    }

    private function scanLayers(): array
    {
        $layers = [];

        foreach (self::LAYER_DIRECTORIES as $name => $directory) {
            $layers[$name] = [
                'path' => $directory,
                'exists' => is_dir($this->path($directory)),
                'files' => count($this->files($directory)),
            ];
        }

        return $layers;
    }

    private function summarizeRoutes(array $routes): array
    {
        $summary = ['total' => 0, 'api' => 0, 'web' => 0, 'closure' => 0];

        foreach ($routes as $route) {
            $action = $route['action'] ?? '';
            $uri = ltrim($route['uri'] ?? '', '/');
            $isClosure = $action === 'Closure';

            if (! $isClosure && ! str_starts_with($action, 'App\\')) {
                continue;
            }

            if ($isClosure && in_array($uri, ['up', 'storage/{path}'], true)) {
                continue;
            }

            $summary['total']++;

            if ($isClosure) {
                $summary['closure']++;
            }

            $isApi = $uri === 'api'
                || str_starts_with($uri, 'api/')
                || in_array('api', $route['middleware'] ?? [], true);

            $summary[$isApi ? 'api' : 'web']++;
        }

        return $summary;
    }

    private function scanFrontend(): array
    {
        $package = $this->readJson('package.json');
        $composer = $this->readJson('composer.json');
        $dependencies = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);

        $stacks = [];

        foreach (['@inertiajs/vue3', '@inertiajs/react', '@inertiajs/svelte'] as $inertia) {
            if (isset($dependencies[$inertia])) {
                $stacks[] = 'inertia';
                break;
            }
        }

        foreach (['vue', 'react', 'svelte'] as $framework) {
            if (isset($dependencies[$framework])) {
                $stacks[] = $framework;
            }
        }

        if (isset($composer['require']['livewire/livewire'])) {
            $stacks[] = 'livewire';
        }

        $bladeViews = count($this->files('resources/views'));

        if ($stacks === [] && $bladeViews > 0) {
            $stacks[] = 'blade';
        }

        $extensions = ['vue', 'jsx', 'tsx', 'svelte'];

        return [
            'stacks' => $stacks,
            'blade_views' => $bladeViews,
            'js_pages' => count($this->files('resources/js/Pages', $extensions))
                + count($this->files('resources/js/pages', $extensions)),
            'js_components' => count($this->files('resources/js/Components', $extensions))
                + count($this->files('resources/js/components', $extensions)),
        ];
    }

    private function files(string $relativeDirectory, array $extensions = ['php']): array
    {
        $directory = $this->path($relativeDirectory);

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    private function readJson(string $relativePath): array
    {
        $path = $this->path($relativePath);

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function path(string $relative): string
    {
        return $this->basePath.'/'.$relative;
    }

    private function relative(string $absolute): string
    {
        $absolute = str_replace('\\', '/', $absolute);

        return ltrim(substr($absolute, strlen($this->basePath)), '/');
    }
}
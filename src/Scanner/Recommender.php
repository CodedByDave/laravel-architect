<?php

namespace LaravelArchitect\Architect\Scanner;

class Recommender
{
    public function fromScan(array $scan, array $enabledLayers = []): array
    {
        $enabled = fn (string $layer): bool => $enabledLayers[$layer] ?? true;
        $layers = $scan['layers'] ?? [];
        $controllers = array_values(array_filter(
            $scan['controllers'] ?? [],
            fn (array $controller): bool => ! isset($controller['parse_error'])
        ));

        $recommendations = [];

        $withDataAccess = $this->files($controllers, fn (array $c): bool => ! empty($c['model_calls']) || ! empty($c['db_facade']));
        $withFatMethods = $this->files($controllers, fn (array $c): bool => ! empty($c['fat_methods']));
        $needsServices = array_values(array_unique(array_merge($withDataAccess, $withFatMethods)));

        if ($enabled('services') && $needsServices !== []) {
            $recommendations[] = ($layers['services']['exists'] ?? false)
                ? $this->recommendation(
                    count($needsServices).' controller(s) still hold database logic or very long methods. Move that logic into your existing services.',
                    $needsServices
                )
                : $this->recommendation(
                    'Create a Services layer (php artisan make:service). '.count($needsServices).' controller(s) hold database logic or very long methods.',
                    $needsServices
                );
        }

        if ($enabled('repositories') && ! ($layers['repositories']['exists'] ?? false) && $withDataAccess !== []) {
            $recommendations[] = $this->recommendation(
                'Optional: add a Repositories layer (php artisan make:repository) to wrap data access. Many apps work well with Eloquent used directly inside services.',
                []
            );
        }

        $withInlineValidation = $this->files($controllers, fn (array $c): bool => ! empty($c['inline_validation']));
        if ($withInlineValidation !== []) {
            $recommendations[] = $this->recommendation(
                'Move inline validation into Form Requests (php artisan make:request).',
                $withInlineValidation
            );
        }

        $modelsWithConstants = [];
        foreach ($scan['models'] ?? [] as $model) {
            if (($model['status_constants'] ?? 0) > 0) {
                $modelsWithConstants[] = $model['file'];
            }
        }

        if ($enabled('enums') && $modelsWithConstants !== []) {
            $recommendations[] = $this->recommendation(
                'Consider backed enums for STATUS_/TYPE_/ROLE_/STATE_ constants.',
                $modelsWithConstants
            );
        }

        $apiOutsideApiFolder = $this->files($controllers, fn (array $c): bool => $c['kind'] === 'api' && ! $c['in_api_folder']);
        if ($apiOutsideApiFolder !== []) {
            $recommendations[] = $this->recommendation(
                'Group API controllers under Http/Controllers/Api.',
                $apiOutsideApiFolder
            );
        }

        $mixed = $this->files($controllers, fn (array $c): bool => $c['kind'] === 'mixed');
        if ($mixed !== []) {
            $recommendations[] = $this->recommendation(
                'These controllers return both JSON and views. Consider splitting them into Web and Api controllers.',
                $mixed
            );
        }

        $closureRoutes = $scan['counts']['closure_routes'] ?? 0;
        if ($closureRoutes > 0) {
            $recommendations[] = $this->recommendation(
                $closureRoutes.' route(s) use closures. Move them into controllers so they can be organized and cached.',
                []
            );
        }

        if ($recommendations === []) {
            $recommendations[] = $this->recommendation('No structural issues found by the current rules.', []);
        }

        return $recommendations;
    }

    private function files(array $controllers, callable $filter): array
    {
        return array_values(array_map(
            fn (array $controller): string => $controller['file'],
            array_filter($controllers, $filter)
        ));
    }

    private function recommendation(string $message, array $items): array
    {
        return ['message' => $message, 'items' => $items];
    }
}
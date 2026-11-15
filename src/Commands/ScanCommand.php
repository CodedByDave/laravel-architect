<?php

namespace LaravelArchitect\Architect\Commands;

use Illuminate\Console\Command;
use LaravelArchitect\Architect\Scanner\ProjectScanner;
use LaravelArchitect\Architect\Scanner\Recommender;

class ScanCommand extends Command
{
    protected $signature = 'architect:scan
                            {--layer=* : Only scan these layers: repositories, services, actions, controllers, models (repeatable)}
                            {--deep : Look for misplaced classes anywhere under app/, not only at the top of each layer folder}
                            {--json : Print the full result as JSON instead of a report}';

    private const LAYERS = ['repositories', 'services', 'actions', 'controllers', 'models'];

    protected $description = 'Analyze the project structure and recommend improvements (read-only)';

    public function handle(): int
    {
        $layers = array_values(array_unique(array_map('strtolower', (array) $this->option('layer'))));
        $unknown = array_diff($layers, self::LAYERS);

        if ($unknown !== []) {
            $this->components->error('Unknown layer(s): '.implode(', ', $unknown).'. Choose from: '.implode(', ', self::LAYERS).'.');

            return self::FAILURE;
        }

        $scan = (new ProjectScanner(base_path(), null, (array) config('architect.domains', [])))
            ->scan($this->routeSummaries(), $layers, (bool) $this->option('deep'));
        $scan['recommendations'] = (new Recommender())->fromScan($scan, (array) config('architect.layers', []));

        if ($this->option('json')) {
            $this->line((string) json_encode($scan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->report($scan);

        return self::SUCCESS;
    }

    private function report(array $scan): void
    {
        $this->newLine();
        $this->components->info('Laravel Architect - Codebase Analysis');

        $scoped = $scan['scope']['layers'] !== [];

        if ($scoped || $scan['scope']['deep']) {
            $this->line('  <options=bold>Scope</> '.($scoped ? implode(', ', $scan['scope']['layers']) : 'all layers').($scan['scope']['deep'] ? ' (deep)' : ''));
            $this->newLine();
        }

        if (! $scoped) {
            $this->line('  <options=bold>Found</>');
            $counts = $scan['counts'];
            $this->components->twoColumnDetail('Controllers', (string) $counts['controllers']);
            $this->components->twoColumnDetail('Models', (string) $counts['models']);
            $this->components->twoColumnDetail('Form Requests', (string) $counts['form_requests']);
            $this->components->twoColumnDetail(
                'Routes',
                sprintf('%d (api: %d, web: %d)', $counts['routes'], $counts['api_routes'], $counts['web_routes'])
            );

            $frontend = $scan['frontend'];
            $this->components->twoColumnDetail(
                'Frontend',
                $frontend['stacks'] === [] ? 'none detected' : implode(', ', $frontend['stacks'])
            );
        }

        $this->newLine();
        $this->line('  <options=bold>Layers</>');
        foreach ($scan['layers'] as $name => $layer) {
            if ($scoped && ! in_array($name, $scan['scope']['layers'], true)) {
                continue;
            }

            $this->components->twoColumnDetail(
                ucfirst($name).' ('.$layer['path'].')',
                $layer['exists'] ? '<fg=green>present</> ('.$layer['files'].' files)' : '<fg=yellow>missing</>'
            );
        }

        $broken = array_filter($scan['controllers'], fn (array $c): bool => isset($c['parse_error']));
        if ($broken !== []) {
            $this->newLine();
            $this->line('  <options=bold>Could not parse</>');
            foreach ($broken as $controller) {
                $this->line('    <fg=red>-</> '.$controller['file'].': '.$controller['parse_error']);
            }
        }

        if ($this->output->isVerbose()) {
            $this->newLine();
            $this->table(
                ['Controller', 'Kind', 'Methods', 'Model calls', 'Inline validation', 'Fat methods'],
                array_map(fn (array $c): array => [
                    $c['file'],
                    $c['kind'],
                    $c['methods'],
                    count($c['model_calls']) + ($c['db_facade'] ? 1 : 0),
                    $c['inline_validation'] ? 'yes' : 'no',
                    implode(', ', $c['fat_methods']),
                ], array_filter($scan['controllers'], fn (array $c): bool => ! isset($c['parse_error'])))
            );
        }

        $this->newLine();
        $this->line('  <options=bold>Recommendations</>');
        foreach ($scan['recommendations'] as $recommendation) {
            $this->line('  <fg=yellow>*</> '.$recommendation['message']);

            if (isset($recommendation['reason'])) {
                $this->line('      <fg=gray>Why:</> '.$recommendation['reason']);
            }

            foreach ($recommendation['items'] as $item) {
                $this->line('      - '.$item);
            }
        }

        $this->newLine();
        $this->line('  <fg=gray>Read-only: no files were changed. Use -v for per-controller details, --json for raw output.</>');
        $this->newLine();
    }

    private function routeSummaries(): array
    {
        $summaries = [];

        foreach ($this->laravel->make('router')->getRoutes() as $route) {
            $summaries[] = [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'action' => $route->getActionName(),
                'middleware' => array_values(array_filter($route->gatherMiddleware(), 'is_string')),
            ];
        }

        return $summaries;
    }
}
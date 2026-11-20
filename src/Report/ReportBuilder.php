<?php

namespace LaravelArchitect\Architect\Report;

use FilesystemIterator;
use LaravelArchitect\Architect\Scanner\CodeAnalyzer;
use LaravelArchitect\Architect\Scanner\MisplacedClassDetector;
use PhpParser\Error as ParseError;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Builds the architecture health report: a score, a per-domain inventory and a list of findings.
 * Read-only - it never writes to the project.
 */
class ReportBuilder
{
    /** A class may depend on classes of an equal or lower rank, never on a higher one. */
    private const RANK = ['model' => 1, 'repository' => 2, 'service' => 3, 'action' => 3, 'controller' => 4, 'request' => 4];

    private const DIRECTORY_LAYER = [
        'Controllers' => 'controller',
        'Models' => 'model',
        'Repositories' => 'repository',
        'Services' => 'service',
        'Actions' => 'action',
    ];

    private const SUFFIX = ['controller' => 'Controller', 'service' => 'Service', 'repository' => 'Repository'];

    private const PLURAL_SUFFIX = ['Controllers' => 'Controller', 'Services' => 'Service', 'Repositories' => 'Repository'];

    private const VERBS = ['Store', 'Create', 'Update', 'Delete', 'Destroy', 'Edit', 'Show', 'Index', 'List', 'Get', 'Find'];

    private const GENERIC_FOLDERS = ['api', 'web', 'admin', 'contracts', 'interfaces', 'concerns', 'traits', 'base'];

    private const PLURAL = ['controller' => 'controllers', 'model' => 'models', 'service' => 'services', 'repository' => 'repositories', 'action' => 'actions', 'request' => 'requests'];

    private const WEIGHTS = ['error' => 5, 'warning' => 3, 'info' => 1];

    private string $basePath;

    private CodeAnalyzer $analyzer;

    /** @var array<string, bool> */
    private array $enabledLayers;

    private array $domainConfig;

    private int $maxControllerMethods;

    private int $maxControllerLines;

    private bool $deep;

    /** @var string[] classes named by app/ files that are not part of a layer (providers, jobs, commands...) */
    private array $outsideReferences = [];

    /**
     * @param  array{layers?: array, domains?: array, max_controller_methods?: int, max_controller_lines?: int, deep?: bool}  $options
     */
    public function __construct(string $basePath, array $options = [])
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $this->analyzer = new CodeAnalyzer();
        $this->enabledLayers = $options['layers'] ?? [];
        $this->domainConfig = $options['domains'] ?? [];
        $this->maxControllerMethods = (int) ($options['max_controller_methods'] ?? 10);
        $this->maxControllerLines = (int) ($options['max_controller_lines'] ?? 200);
        $this->deep = (bool) ($options['deep'] ?? false);
    }

    public function build(?string $domainFilter = null): array
    {
        $this->outsideReferences = [];
        [$classes, $unparsed] = $this->collectClasses();

        $findings = array_merge(
            $this->misplacedFindings(),
            $this->dependencyFindings($classes),
            $this->namingFindings($classes),
            $this->orphanFindings($classes),
            $this->controllerFindings($classes),
            $this->coverageFindings($classes),
        );

        $domains = $this->inventory($classes);

        if ($domainFilter !== null) {
            $wanted = strtolower($domainFilter);
            $domains = array_filter($domains, fn (string $name): bool => strtolower($name) === $wanted, ARRAY_FILTER_USE_KEY);
            $findings = array_values(array_filter($findings, fn (array $f): bool => strtolower((string) $f['domain']) === $wanted));
            $classes = array_values(array_filter($classes, fn (array $c): bool => strtolower($c['domain']) === $wanted));
        }

        usort($findings, fn (array $a, array $b): int => [self::WEIGHTS[$b['severity']], $a['category'], $a['file']]
            <=> [self::WEIGHTS[$a['severity']], $b['category'], $b['file']]);

        ksort($domains);

        return [
            'score' => $this->score($findings, count($classes)),
            'inventory' => ['totals' => $this->totals($domains), 'domains' => $domains],
            'findings' => $findings,
            'unparsed' => $unparsed,
        ];
    }

    /**
     * @return array{0: array<int, array>, 1: string[]}
     */
    private function collectClasses(): array
    {
        $classes = [];
        $unparsed = [];

        foreach ($this->files('app') as $relative) {
            $layer = $this->layerOfPath($relative);

            if ($relative === 'app/Http/Controllers/Controller.php') {
                continue;
            }

            try {
                $info = $this->analyzer->inspectClass((string) file_get_contents($this->basePath.'/'.$relative));
            } catch (ParseError) {
                $unparsed[] = $relative;

                continue;
            }

            if ($layer === null) {
                $this->outsideReferences = array_merge($this->outsideReferences, $info['references']);

                continue;
            }

            if ($info['class'] === null) {
                continue;
            }

            $classes[] = $info + [
                'file' => $relative,
                'layer' => $layer,
                'domain' => $this->domainOf($relative, $layer, $info['short']),
            ];
        }

        return [$classes, $unparsed];
    }

    // ---------------------------------------------------------------- inventory

    private function inventory(array $classes): array
    {
        $tests = $this->testNames();
        $domains = [];

        foreach ($classes as $class) {
            $domains[$class['domain']] ??= ['controllers' => 0, 'models' => 0, 'services' => 0, 'repositories' => 0, 'actions' => 0, 'requests' => 0, 'tests' => 0];
            $domains[$class['domain']][self::PLURAL[$class['layer']]]++;
        }

        foreach ($domains as $name => $counts) {
            $domains[$name]['tests'] = count(array_filter($tests, fn (string $t): bool => stripos($t, $name) !== false));
        }

        return $domains;
    }

    private function totals(array $domains): array
    {
        $totals = ['controllers' => 0, 'models' => 0, 'services' => 0, 'repositories' => 0, 'actions' => 0, 'requests' => 0];

        foreach ($domains as $counts) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $counts[$key];
            }
        }

        return $totals;
    }

    // ---------------------------------------------------------------- findings

    private function misplacedFindings(): array
    {
        $findings = [];

        foreach ((new MisplacedClassDetector($this->basePath, $this->domainConfig))->detect([], $this->deep) as $item) {
            $findings[] = $this->finding(
                'misplaced',
                $item['folder_exists'] ? 'warning' : 'info',
                $item['file'],
                $item['class'],
                $item['domain'],
                $item['class'].' belongs to the '.$item['domain'].' domain but is not in its folder.',
                'Move it to '.$item['target'].'.',
                $item['reason'],
            );
        }

        return $findings;
    }

    private function dependencyFindings(array $classes): array
    {
        $findings = [];
        $byClass = array_column($classes, 'layer', 'class');

        foreach ($classes as $class) {
            $rank = self::RANK[$class['layer']];
            $root = strtok($class['class'], '\\');
            $higher = [];

            foreach ($class['references'] as $reference) {
                if (strtok($reference, '\\') !== $root) {
                    continue;
                }

                $targetLayer = $byClass[$reference] ?? $this->layerOfClass($reference);

                if ($targetLayer !== null && self::RANK[$targetLayer] > $rank) {
                    $higher[$targetLayer][] = $reference;
                }
            }

            foreach ($higher as $targetLayer => $targets) {
                sort($targets);
                $names = implode(', ', array_map(fn (string $t): string => $this->short($t), array_slice($targets, 0, 3)))
                    .(count($targets) > 3 ? ' and '.(count($targets) - 3).' more' : '');

                $findings[] = $this->finding(
                    'layer_violation',
                    $targetLayer === 'request' ? 'warning' : 'error',
                    $class['file'],
                    $class['short'],
                    $class['domain'],
                    sprintf('%s (%s) depends on a higher layer: %s (%s).', $class['short'], $class['layer'], $names, $targetLayer),
                    'Lower layers should not know about higher ones. Pass plain values or DTOs down, and let the '.$targetLayer.' call this class instead.',
                );
            }

            if ($class['layer'] !== 'controller' && $class['layer'] !== 'request' && $class['response_methods'] !== []) {
                $findings[] = $this->finding(
                    'layer_violation',
                    'error',
                    $class['file'],
                    $class['short'],
                    $class['domain'],
                    sprintf('%s (%s) returns an HTTP response from %s.', $class['short'], $class['layer'], implode(', ', array_map(fn (string $m): string => $m.'()', $class['response_methods']))),
                    'Return data or throw an exception, and build the response in the controller.',
                );
            }
        }

        return $findings;
    }

    private function namingFindings(array $classes): array
    {
        $findings = [];

        foreach ($classes as $class) {
            $short = $class['short'];
            $layer = $class['layer'];
            $folder = $this->layerFolder($class['file']);

            if (pathinfo($class['file'], PATHINFO_FILENAME) !== $short) {
                $findings[] = $this->finding(
                    'naming',
                    'warning',
                    $class['file'],
                    $short,
                    $class['domain'],
                    sprintf('The class is %s but the file is %s.php, so autoloading will fail.', $short, pathinfo($class['file'], PATHINFO_FILENAME)),
                    'Rename the file or the class so they match.',
                );
            }

            if (! isset(self::SUFFIX[$layer]) || $class['abstract']) {
                continue;
            }

            $suffix = self::SUFFIX[$layer];

            if (str_ends_with($short, $suffix)) {
                continue;
            }

            foreach (self::PLURAL_SUFFIX as $plural => $singular) {
                if (str_ends_with($short, $plural)) {
                    $findings[] = $this->finding('naming', 'info', $class['file'], $short, $class['domain'],
                        sprintf('%s ends in "%s"; use the singular "%s".', $short, $plural, $singular),
                        'Rename it to '.substr($short, 0, -strlen($plural)).$singular.'.');

                    continue 2;
                }
            }

            foreach (self::SUFFIX as $otherLayer => $otherSuffix) {
                if ($otherLayer !== $layer && str_ends_with($short, $otherSuffix)) {
                    $findings[] = $this->finding('naming', 'warning', $class['file'], $short, $class['domain'],
                        sprintf('%s is named like a %s but lives in %s.', $short, $otherLayer, $folder),
                        'Move it to the '.$otherLayer.' layer, or rename it.');

                    continue 2;
                }
            }

            $findings[] = $this->finding('naming', 'info', $class['file'], $short, $class['domain'],
                sprintf('%s lives in %s but its name does not end in "%s".', $short, $folder, $suffix),
                'Rename it to '.$short.$suffix.'.');
        }

        return $findings;
    }

    private function orphanFindings(array $classes): array
    {
        $referenced = [];

        foreach ($classes as $class) {
            foreach ($class['references'] as $reference) {
                $referenced[$reference] = true;
            }
        }

        foreach (array_merge($this->outsideReferences, $this->externalReferences()) as $reference) {
            $referenced[$reference] = true;
        }

        $findings = [];

        foreach ($classes as $class) {
            if (! in_array($class['layer'], ['service', 'repository', 'action'], true) || isset($referenced[$class['class']])) {
                continue;
            }

            $findings[] = $this->finding(
                'orphan',
                'info',
                $class['file'],
                $class['short'],
                $class['domain'],
                $class['short'].' is never injected or used.',
                'Delete it, or wire it into a controller or provider. Classes resolved by a string built at runtime cannot be detected.',
            );
        }

        return $findings;
    }

    private function controllerFindings(array $classes): array
    {
        $models = array_column(array_filter($classes, fn (array $c): bool => $c['layer'] === 'model'), 'class');
        $businessLayerOn = $this->layerEnabled('services') || $this->layerEnabled('repositories') || $this->layerEnabled('actions');
        $findings = [];

        foreach ($classes as $class) {
            if ($class['layer'] !== 'controller') {
                continue;
            }

            try {
                $analysis = $this->analyzer->analyzeController((string) file_get_contents($this->basePath.'/'.$class['file']), $models);
            } catch (ParseError) {
                continue;
            }

            $severe = [];
            $minor = [];

            if ($analysis['fat_methods'] !== []) {
                $severe[] = sprintf('%d method%s over %d lines (%s)', count($analysis['fat_methods']), count($analysis['fat_methods']) > 1 ? 's' : '', CodeAnalyzer::FAT_METHOD_LINES,
                    implode(', ', array_map(fn (string $m): string => $m.'()', array_slice($analysis['fat_methods'], 0, 3))));
            }

            if ($businessLayerOn && $analysis['model_calls'] !== []) {
                $severe[] = 'calls Eloquent directly ('.implode(', ', array_slice($analysis['model_calls'], 0, 3)).')';
            }

            if ($businessLayerOn && $analysis['db_facade']) {
                $severe[] = 'uses the DB facade directly';
            }

            if ($analysis['methods'] > $this->maxControllerMethods) {
                $minor[] = sprintf('has %d public methods (limit %d)', $analysis['methods'], $this->maxControllerMethods);
            }

            if ($analysis['lines'] > $this->maxControllerLines) {
                $minor[] = sprintf('is %d lines long (limit %d)', $analysis['lines'], $this->maxControllerLines);
            }

            if ($analysis['inline_validation']) {
                $minor[] = 'validates inline instead of using a Form Request';
            }

            if ($severe === [] && $minor === []) {
                continue;
            }

            $findings[] = $this->finding(
                'fat_controller',
                $severe !== [] ? 'warning' : 'info',
                $class['file'],
                $class['short'],
                $class['domain'],
                $class['short'].' '.implode('; ', array_merge($severe, $minor)).'.',
                'Keep controllers thin: move the logic into a service or repository (php artisan make:domain '.$class['domain'].').',
            );
        }

        return $findings;
    }

    private function coverageFindings(array $classes): array
    {
        $business = array_values(array_filter(['services' => 'service', 'repositories' => 'repository'], fn (string $key): bool => $this->layerEnabled($key), ARRAY_FILTER_USE_KEY));
        $tests = $this->testNames();
        $findings = [];
        $byDomain = [];

        foreach ($classes as $class) {
            $byDomain[$class['domain']][$class['layer']][] = $class;
        }

        foreach ($byDomain as $domain => $layers) {
            $hasController = isset($layers['controller']);
            $hasBusiness = isset($layers['service']) || isset($layers['repository']) || isset($layers['action']);

            if ($hasController && ! $hasBusiness && $business !== []) {
                $has = array_filter([
                    'a controller',
                    isset($layers['model']) ? 'a model' : null,
                    isset($layers['request']) ? 'a form request' : null,
                ]);
                $missing = implode(' or ', $business);

                $findings[] = $this->finding(
                    'coverage_gap',
                    'warning',
                    $layers['controller'][0]['file'],
                    $domain,
                    $domain,
                    sprintf('%s has %s but no %s.', $domain, $this->join(array_values($has)), $missing),
                    'Run php artisan make:domain '.$domain.' and move the logic out of the controller.',
                );
            }

            foreach ($layers['service'] ?? [] as $service) {
                if ($service['abstract']) {
                    continue;
                }

                $matched = array_filter($tests, fn (string $t): bool => stripos($t, $service['short']) !== false || stripos($t, $domain) !== false);

                if ($matched === []) {
                    $findings[] = $this->finding(
                        'test_gap',
                        'info',
                        $service['file'],
                        $service['short'],
                        $domain,
                        $service['short'].' has no matching test.',
                        'Add tests/Unit/'.$service['short'].'Test.php.',
                    );
                }
            }
        }

        return $findings;
    }

    // ---------------------------------------------------------------- score

    private function score(array $findings, int $classCount): array
    {
        $penalty = 0;
        $bySeverity = ['error' => 0, 'warning' => 0, 'info' => 0];
        $byCategory = [];

        foreach ($findings as $finding) {
            $penalty += self::WEIGHTS[$finding['severity']];
            $bySeverity[$finding['severity']]++;
            $byCategory[$finding['category']] = ($byCategory[$finding['category']] ?? 0) + 1;
        }

        // One error costs 5 points per class; a project of 10 classes with 1 error scores 90.
        $value = $classCount === 0 ? 100 : max(0, 100 - (int) round($penalty / $classCount * 20));

        return [
            'value' => $value,
            'grade' => match (true) {
                $value >= 90 => 'A',
                $value >= 80 => 'B',
                $value >= 70 => 'C',
                $value >= 60 => 'D',
                default => 'F',
            },
            'penalty' => $penalty,
            'classes' => $classCount,
            'by_severity' => $bySeverity,
            'by_category' => $byCategory,
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function finding(string $category, string $severity, string $file, string $class, string $domain, string $message, string $suggestion, ?string $detail = null): array
    {
        return [
            'category' => $category,
            'severity' => $severity,
            'file' => $file,
            'class' => $class,
            'domain' => $domain,
            'message' => $message,
            'suggestion' => $suggestion,
            'detail' => $detail,
        ];
    }

    private function layerEnabled(string $layer): bool
    {
        return (bool) ($this->enabledLayers[$layer] ?? true);
    }

    private function layerOfPath(string $relative): ?string
    {
        $layer = null;
        $directories = explode('/', dirname($relative));

        foreach ($directories as $index => $directory) {
            if ($directory === 'Requests' && ($directories[$index - 1] ?? null) === 'Http') {
                $layer = 'request';
            } elseif (isset(self::DIRECTORY_LAYER[$directory])) {
                $layer = self::DIRECTORY_LAYER[$directory];
            }
        }

        return $layer;
    }

    private function layerOfClass(string $fqcn): ?string
    {
        $segments = explode('\\', $fqcn);
        array_pop($segments);
        $layer = null;

        foreach ($segments as $index => $segment) {
            if ($segment === 'Requests' && ($segments[$index - 1] ?? null) === 'Http') {
                $layer = 'request';
            } elseif (isset(self::DIRECTORY_LAYER[$segment])) {
                $layer = self::DIRECTORY_LAYER[$segment];
            }
        }

        return $layer;
    }

    private function layerFolder(string $relative): string
    {
        $directories = explode('/', dirname($relative));
        $found = 'app';

        foreach ($directories as $index => $directory) {
            if (isset(self::DIRECTORY_LAYER[$directory])) {
                $found = implode('/', array_slice($directories, 0, $index + 1));
            }
        }

        return $found;
    }

    private function domainOf(string $relative, string $layer, string $short): string
    {
        $directories = explode('/', dirname($relative));
        $index = null;

        foreach ($directories as $i => $directory) {
            if (isset(self::DIRECTORY_LAYER[$directory]) || $directory === 'Requests') {
                $index = $i;
            }
        }

        if ($index !== null) {
            $before = $directories[$index - 1] ?? 'app';

            // app/Customer/Services/... : the folder above the layer folder names the domain.
            if (! in_array($before, ['app', 'Http'], true)) {
                return $before;
            }

            foreach (array_slice($directories, $index + 1) as $below) {
                if (! in_array(strtolower($below), self::GENERIC_FOLDERS, true) && ! preg_match('/^v\d+$/i', $below)) {
                    return $below;
                }
            }
        }

        $name = $short;

        foreach (['Controllers', 'Controller', 'Services', 'Service', 'Repositories', 'Repository', 'Action', 'Request'] as $suffix) {
            if (str_ends_with($name, $suffix) && $name !== $suffix) {
                $name = substr($name, 0, -strlen($suffix));
                break;
            }
        }

        if ($layer === 'request' || $layer === 'action') {
            foreach (self::VERBS as $verb) {
                if (str_starts_with($name, $verb) && strlen($name) > strlen($verb) && ctype_upper($name[strlen($verb)])) {
                    $name = substr($name, strlen($verb));
                    break;
                }
            }
        }

        return $name;
    }

    /** @return string[] test file names without extension, e.g. OrderServiceTest */
    private function testNames(): array
    {
        return array_map(fn (string $f): string => pathinfo($f, PATHINFO_FILENAME), $this->files('tests'));
    }

    /** @return string[] class names mentioned outside app/: routes, config, bootstrap, database and Blade views */
    private function externalReferences(): array
    {
        $references = [];

        foreach (['routes', 'config', 'bootstrap', 'database'] as $directory) {
            foreach ($this->files($directory) as $relative) {
                try {
                    $info = $this->analyzer->inspectClass((string) file_get_contents($this->basePath.'/'.$relative));
                } catch (ParseError) {
                    continue;
                }

                $references = array_merge($references, $info['references']);
            }
        }

        foreach ($this->files('resources/views', ['php']) as $relative) {
            if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*(?:\\\\{1,2}[A-Za-z_][A-Za-z0-9_]*)+/', (string) file_get_contents($this->basePath.'/'.$relative), $matches)) {
                foreach ($matches[0] as $match) {
                    $references[] = str_replace('\\\\', '\\', $match);
                }
            }
        }

        return $references;
    }

    private function short(string $fqcn): string
    {
        return substr($fqcn, (int) strrpos($fqcn, '\\') + 1);
    }

    private function join(array $items): string
    {
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }

    /** @return string[] relative paths */
    private function files(string $directory, array $extensions = ['php']): array
    {
        $path = $this->basePath.'/'.$directory;

        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->basePath)), '/');
            }
        }

        sort($files);

        return $files;
    }
}

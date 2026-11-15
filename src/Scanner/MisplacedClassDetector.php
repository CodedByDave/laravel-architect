<?php

namespace LaravelArchitect\Architect\Scanner;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds repositories/services/actions that sit loose in their layer folder
 * (e.g. app/Repositories/PaymongoRepository.php) although they belong to a
 * business domain (e.g. Payment) that has - or deserves - its own folder.
 */
class MisplacedClassDetector
{
    private const TYPES = [
        'repository' => ['layer' => 'Repositories', 'config' => 'repositories'],
        'service' => ['layer' => 'Services', 'config' => 'services'],
        'action' => ['layer' => 'Actions', 'config' => 'actions'],
    ];

    private string $basePath;

    /** Folder names that are framework/layer folders, never business domains. */
    private const NOT_DOMAINS = [
        'http', 'controllers', 'middleware', 'requests', 'resources', 'models', 'providers', 'console', 'commands',
        'exceptions', 'repositories', 'services', 'actions', 'enums', 'dtos', 'dto', 'policies', 'jobs', 'events',
        'listeners', 'mail', 'notifications', 'observers', 'rules', 'traits', 'contracts', 'interfaces', 'helpers',
        'casts', 'livewire', 'view', 'views', 'components', 'api', 'web', 'support', 'concerns', 'scopes', 'modules',
        'lang', 'broadcasting', 'filament', 'nova', 'transformers', 'queries', 'facades', 'abstracts',
    ];

    /** Ancestor folders whose children are framework plumbing, not domains. */
    private const NOT_DOMAIN_PARENTS = ['http', 'console', 'models', 'providers', 'exceptions', 'view', 'livewire'];

    private CodeAnalyzer $analyzer;

    private array $configuredDomains;

    /** @var array<string, array{keywords: string[], label: ?string}>|null */
    private ?array $domains = null;

    /** @var string[]|null */
    private ?array $appDirectories = null;

    public function __construct(string $basePath, array $domains)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath), '/');
        $this->configuredDomains = $domains;
        $this->analyzer = new CodeAnalyzer();
    }

    /**
     * @param  string[]  $layers  limit to these layers (repositories, services, actions); empty = all
     * @param  bool  $deep  scan every PHP file under app/, not just the top level of each layer folder
     */
    public function detect(array $layers = [], bool $deep = false): array
    {
        $candidates = [];
        $this->domains = $this->buildDomains();

        foreach (self::TYPES as $type => $meta) {
            if ($layers !== [] && ! in_array($meta['config'], $layers, true)) {
                continue;
            }

            $layerDir = 'app/'.$meta['layer'];
            $files = $deep ? $this->deepFiles($type) : array_map(fn (string $f): string => $layerDir.'/'.$f, $this->looseFiles($layerDir));

            foreach ($files as $relativePath) {
                $file = basename($relativePath);
                $class = pathinfo($file, PATHINFO_FILENAME);
                $base = $this->baseName($class, $type);
                $match = $this->matchDomain($base);

                if ($match === null || $this->insideDomainFolder($relativePath, $match['domain'])) {
                    continue;
                }

                $candidates[] = [
                    'file' => $relativePath,
                    'class' => $class,
                    'type' => $type,
                    'layer' => $meta['config'],
                    'domain' => $match['domain'],
                    'name' => $match['name'],
                    'label' => $this->domains[$match['domain']]['label'],
                    'layer_dir' => $layerDir,
                    'layer_folder' => $meta['layer'],
                ];
            }
        }

        $perDomain = array_count_values(array_column($candidates, 'domain'));
        $results = [];

        foreach ($candidates as $candidate) {
            $existing = $this->findDomainDirectory($candidate['domain']);

            // Without a domain folder, only suggest one when several classes share the domain.
            if ($existing === null && $perDomain[$candidate['domain']] < 2) {
                continue;
            }

            $target = $this->targetDirectory($candidate, $existing);

            $results[] = [
                'file' => $candidate['file'],
                'class' => $candidate['class'],
                'type' => $candidate['type'],
                'layer' => $candidate['layer'],
                'domain' => $candidate['domain'],
                'target' => $target.'/'.basename($candidate['file']),
                'folder_exists' => $existing !== null,
                'reason' => $this->reason($candidate, $existing, $candidates),
            ];
        }

        return $results;
    }

    private function reason(array $c, ?string $existingDomainDir, array $allCandidates): string
    {
        $path = $this->basePath.'/'.$c['file'];
        $facts = $this->analyzer->describeClass(is_file($path) ? (string) file_get_contents($path) : '');

        $words = strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $c['name'])));
        $verb = match ($c['type']) {
            'repository' => 'reads and writes',
            'service' => 'implements the business rules for',
            default => 'runs a single task on',
        };

        // What it touches: tables/models if we can see them, otherwise what the name says.
        $touches = [];
        if ($facts['tables'] !== []) {
            $touches[] = $this->list(array_map(fn (string $t): string => '`'.$t.'`', $facts['tables'])).' table'.(count($facts['tables']) > 1 ? 's' : '');
        }
        if ($facts['models'] !== []) {
            $touches[] = $this->list($facts['models']).(count($facts['models']) > 1 ? ' models' : ' model');
        }
        $subject = $touches !== [] ? implode(' and the ', $touches) : $words.' data';

        $sentence = sprintf('%s %s %s', $c['class'], $verb, $subject);

        $methods = array_slice($facts['methods'], 0, 3);
        if ($methods !== []) {
            $sentence .= ' through '.$this->list(array_map(fn (string $m): string => $m.'()', $methods))
                .(count($facts['methods']) > 3 ? ' and '.(count($facts['methods']) - 3).' more' : '');
        }

        if ($facts['hosts'] !== []) {
            $sentence .= ' and calls '.$this->list($facts['hosts']);
        } elseif (in_array('Http', $facts['facades'], true)) {
            $sentence .= ' and calls an external HTTP API';
        }

        if (strcasecmp($c['name'], $c['domain']) !== 0 && $c['label'] !== null) {
            $sentence .= ', so it is part of your '.$c['name'].' '.$c['label'];
        } else {
            $sentence .= ', so it only deals with '.$c['domain'].' concerns';
        }

        return $sentence.'. '.$this->siblingSentence($c, $existingDomainDir, $allCandidates);
    }

    private function siblingSentence(array $c, ?string $existingDomainDir, array $allCandidates): string
    {
        $siblings = [];

        if ($existingDomainDir !== null) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->basePath.'/'.$existingDomainDir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php' && $file->getBasename('.php') !== $c['class']) {
                    $siblings[] = $file->getBasename('.php');
                }
            }
        } else {
            foreach ($allCandidates as $other) {
                if ($other['domain'] === $c['domain'] && $other['class'] !== $c['class']) {
                    $siblings[] = $other['class'];
                }
            }
        }

        sort($siblings);

        if ($siblings === []) {
            return 'Keeping it in '.$c['domain'].' means everything about '.$c['domain'].' lives in one place.';
        }

        return $c['domain'].' already holds '.$this->list(array_slice($siblings, 0, 3))
            .(count($siblings) > 3 ? ' and '.(count($siblings) - 3).' more' : '').', so keep them together.';
    }

    private function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }

    private function targetDirectory(array $c, ?string $existing): string
    {
        if ($existing === null) {
            return $c['layer_dir'].'/'.$c['domain'];
        }

        // Domain folder already lives inside the layer folder, e.g. app/Repositories/Payment.
        if (str_starts_with($existing.'/', $c['layer_dir'].'/')) {
            return $existing;
        }

        // Domain folder is a module-style folder, e.g. app/Payment or app/Modules/Payment.
        return $existing.'/'.$c['layer_folder'];
    }

    /** Domain folder anywhere under app/, shallowest first; layer folders themselves are ignored. */
    private function findDomainDirectory(string $domain): ?string
    {
        $wanted = [strtolower($domain), strtolower($domain).'s'];
        $layerFolders = array_map('strtolower', array_column(self::TYPES, 'layer'));
        $best = null;

        foreach ($this->appDirectories() as $directory) {
            $name = strtolower(basename($directory));

            if (! in_array($name, $wanted, true) || in_array($name, $layerFolders, true)) {
                continue;
            }

            if ($best === null || substr_count($directory, '/') < substr_count($best, '/')) {
                $best = $directory;
            }
        }

        return $best;
    }

    /** @return string[] relative directories under app/ */
    private function appDirectories(): array
    {
        if ($this->appDirectories !== null) {
            return $this->appDirectories;
        }

        $this->appDirectories = [];
        $root = $this->basePath.'/app';

        if (! is_dir($root)) {
            return $this->appDirectories;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $this->appDirectories[] = ltrim(substr(str_replace('\\', '/', $item->getPathname()), strlen($this->basePath)), '/');
            }
        }

        return $this->appDirectories;
    }

    /** @return string[] file names directly inside $relativeDirectory */
    private function looseFiles(string $relativeDirectory): array
    {
        $directory = $this->basePath.'/'.$relativeDirectory;

        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        foreach (new FilesystemIterator($directory) as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getFilename();
            }
        }

        sort($files);

        return $files;
    }

    /** @return string[] relative paths of every app/ file whose class name ends with the type suffix */
    private function deepFiles(string $type): array
    {
        $root = $this->basePath.'/app';

        if (! is_dir($root)) {
            return [];
        }

        $suffix = ucfirst($type);
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $name = $file->getBasename('.php');

            if (preg_match('/'.$suffix.'(Interface)?$/', $name)) {
                $files[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->basePath)), '/');
            }
        }

        sort($files);

        return $files;
    }

    private function insideDomainFolder(string $relativePath, string $domain): bool
    {
        $segments = array_map('strtolower', array_slice(explode('/', $relativePath), 0, -1));
        $wanted = [strtolower($domain), strtolower($domain).'s'];

        return array_intersect($segments, $wanted) !== [];
    }

    private function baseName(string $class, string $type): string
    {
        $class = preg_replace('/Interface$/', '', $class);

        return preg_replace('/'.ucfirst($type).'$/', '', $class) ?: $class;
    }

    /** @return array{domain: string, name: string}|null */
    private function matchDomain(string $base): ?array
    {
        $tokens = array_map('strtolower', preg_split('/(?=[A-Z])/', $base, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $lowerBase = strtolower($base);
        $best = null;

        foreach ($this->domains as $domain => $config) {
            foreach ($config['keywords'] as $keyword) {
                $position = array_search($keyword, $tokens, true);

                if ($position === false && str_starts_with($lowerBase, $keyword)) {
                    $position = 0;
                }

                if ($position === false) {
                    continue;
                }

                // Prefer the keyword that appears earliest in the name, then the longest one.
                $rank = [$position, -strlen($keyword)];

                if ($best === null || $rank < $best['rank']) {
                    $best = ['rank' => $rank, 'domain' => (string) $domain];
                }
            }
        }

        return $best === null ? null : ['domain' => $best['domain'], 'name' => $base];
    }

    /**
     * Domains = explicitly configured ones + every business folder found under app/
     * (app/Customer, app/Modules/Order, app/Repositories/Invoice, ...).
     *
     * @return array<string, array{keywords: string[], label: ?string}>
     */
    private function buildDomains(): array
    {
        $domains = [];

        foreach ($this->configuredDomains as $domain => $config) {
            $domains[(string) $domain] = [
                'keywords' => array_map('strtolower', (array) ($config['keywords'] ?? [])),
                'label' => isset($config['label']) ? (string) $config['label'] : strtolower((string) $domain),
            ];
        }

        foreach ($this->appDirectories() as $directory) {
            $segments = explode('/', $directory);
            $name = end($segments);
            $ancestors = array_map('strtolower', array_slice($segments, 1, -1));

            if (str_starts_with($name, '.')
                || in_array(strtolower($name), self::NOT_DOMAINS, true)
                || array_intersect($ancestors, self::NOT_DOMAIN_PARENTS) !== []) {
                continue;
            }

            $existing = null;
            foreach (array_keys($domains) as $known) {
                if (strcasecmp($known, $name) === 0) {
                    $existing = $known;
                }
            }

            $key = $existing ?? $name;
            $domains[$key] ??= ['keywords' => [], 'label' => null];
            $domains[$key]['keywords'] = array_values(array_unique(array_merge(
                $domains[$key]['keywords'],
                [strtolower($name), $this->singular(strtolower($name))]
            )));
        }

        foreach ($domains as $domain => $config) {
            $domains[$domain]['keywords'] = array_values(array_unique(array_merge($config['keywords'], [strtolower($domain)])));
        }

        return $domains;
    }

    private function singular(string $word): string
    {
        return strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss') ? substr($word, 0, -1) : $word;
    }
}

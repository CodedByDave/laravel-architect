<?php

namespace LaravelArchitect\Architect\Report;

class ReportRenderer
{
    public const CATEGORIES = [
        'layer_violation' => 'Layer violations',
        'misplaced' => 'Misplaced classes',
        'fat_controller' => 'Fat controllers',
        'coverage_gap' => 'Coverage gaps',
        'naming' => 'Naming problems',
        'orphan' => 'Orphans',
        'test_gap' => 'Missing tests',
    ];

    public static function markdown(array $report): string
    {
        $score = $report['score'];
        $lines = [
            '# Architecture report',
            '',
            sprintf('**Score: %d/100 (grade %s)** - %d classes analysed, %d finding%s (%d error, %d warning, %d info)',
                $score['value'], $score['grade'], $score['classes'], count($report['findings']), count($report['findings']) === 1 ? '' : 's',
                $score['by_severity']['error'], $score['by_severity']['warning'], $score['by_severity']['info']),
            '',
            '## Inventory',
            '',
            '| Domain | Controllers | Models | Services | Repositories | Actions | Requests | Tests |',
            '|---|---:|---:|---:|---:|---:|---:|---:|',
        ];

        foreach ($report['inventory']['domains'] as $name => $c) {
            $lines[] = sprintf('| %s | %d | %d | %d | %d | %d | %d | %d |', $name, $c['controllers'], $c['models'], $c['services'], $c['repositories'], $c['actions'], $c['requests'], $c['tests']);
        }

        $t = $report['inventory']['totals'];
        $lines[] = sprintf('| **Total** | %d | %d | %d | %d | %d | %d |  |', $t['controllers'], $t['models'], $t['services'], $t['repositories'], $t['actions'], $t['requests']);

        foreach (self::CATEGORIES as $category => $title) {
            $items = array_values(array_filter($report['findings'], fn (array $f): bool => $f['category'] === $category));

            if ($items === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = sprintf('## %s (%d)', $title, count($items));
            $lines[] = '';

            foreach ($items as $f) {
                $lines[] = sprintf('- **%s** `%s` - %s %s', strtoupper($f['severity']), $f['file'], $f['message'], $f['suggestion']);
            }
        }

        if ($report['unparsed'] !== []) {
            $lines[] = '';
            $lines[] = '## Skipped (could not be parsed)';
            $lines[] = '';

            foreach ($report['unparsed'] as $file) {
                $lines[] = '- `'.$file.'`';
            }
        }

        return implode("\n", $lines)."\n";
    }
}

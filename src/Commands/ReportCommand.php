<?php

namespace LaravelArchitect\Architect\Commands;

use Illuminate\Console\Command;
use LaravelArchitect\Architect\Report\ReportBuilder;
use LaravelArchitect\Architect\Report\ReportRenderer;

class ReportCommand extends Command
{
    protected $signature = 'architect:report
                            {--format=table : table, json or markdown}
                            {--output= : Write the report to this file (markdown unless --format=json)}
                            {--domain= : Only report on one domain, e.g. Customer}
                            {--min-score= : Exit with a failure when the score is below this number (0-100), for CI}
                            {--deep : Look for misplaced classes anywhere under app/, not only at the top of each layer folder}';

    protected $description = 'Score the project architecture and list violations, smells and gaps (read-only)';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        $min = $this->option('min-score');

        if (! in_array($format, ['table', 'json', 'markdown'], true)) {
            $this->components->error('Unknown format "'.$format.'". Choose table, json or markdown.');

            return self::FAILURE;
        }

        if ($min !== null && (! is_numeric($min) || $min < 0 || $min > 100)) {
            $this->components->error('--min-score must be a number from 0 to 100.');

            return self::FAILURE;
        }

        $report = (new ReportBuilder(base_path(), [
            'layers' => (array) config('architect.layers', []),
            'domains' => (array) config('architect.domains', []),
            'max_controller_methods' => config('architect.report.max_controller_methods', 10),
            'max_controller_lines' => config('architect.report.max_controller_lines', 200),
            'deep' => (bool) $this->option('deep'),
        ]))->build($this->option('domain') ?: null);

        if ($this->option('domain') && $report['inventory']['domains'] === [] && $report['findings'] === []) {
            $this->components->error('No domain named "'.$this->option('domain').'" was found.');

            return self::FAILURE;
        }

        $output = $this->option('output');

        if ($output) {
            $content = $format === 'json' ? $this->json($report) : ReportRenderer::markdown($report);

            if (@file_put_contents($output, $content) === false) {
                $this->components->error('Could not write '.$output.'.');

                return self::FAILURE;
            }

            $this->components->info('Report written to '.$output.'.');
        } elseif ($format === 'json') {
            $this->line($this->json($report));
        } elseif ($format === 'markdown') {
            $this->getOutput()->write(ReportRenderer::markdown($report));
        } else {
            $this->renderTable($report);
        }

        if ($min !== null && $report['score']['value'] < (float) $min) {
            if ($format !== 'json') {
                $this->components->error(sprintf('Score %d is below the required minimum of %s.', $report['score']['value'], $min));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function json(array $report): string
    {
        return (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function renderTable(array $report): void
    {
        $score = $report['score'];

        $this->newLine();
        $this->components->info('Laravel Architect - Architecture Report');

        $by = $score['by_severity'];
        $this->components->twoColumnDetail('<fg=white;options=bold>Score</>', sprintf('<fg=%s;options=bold>%d/100  (grade %s)</>', $score['value'] >= 80 ? 'green' : ($score['value'] >= 60 ? 'yellow' : 'red'), $score['value'], $score['grade']));
        $this->components->twoColumnDetail('Classes analysed', (string) $score['classes']);
        $this->components->twoColumnDetail('Findings', sprintf('%d error, %d warning, %d info', $by['error'], $by['warning'], $by['info']));

        $this->newLine();
        $this->components->info('Inventory per domain');

        $rows = [];

        foreach ($report['inventory']['domains'] as $name => $c) {
            $rows[] = [$name, $c['controllers'], $c['models'], $c['services'], $c['repositories'], $c['actions'], $c['requests'], $c['tests']];
        }

        $t = $report['inventory']['totals'];
        $rows[] = ['<options=bold>Total</>', $t['controllers'], $t['models'], $t['services'], $t['repositories'], $t['actions'], $t['requests'], ''];

        $this->getOutput()->table(['Domain', 'Controllers', 'Models', 'Services', 'Repositories', 'Actions', 'Requests', 'Tests'], $rows);

        if ($report['findings'] === []) {
            $this->components->info('No findings. The architecture looks clean.');
        }

        foreach (ReportRenderer::CATEGORIES as $category => $title) {
            $items = array_values(array_filter($report['findings'], fn (array $f): bool => $f['category'] === $category));

            if ($items === []) {
                continue;
            }

            $this->newLine();
            $this->components->info(sprintf('%s (%d)', $title, count($items)));

            foreach ($items as $f) {
                $color = ['error' => 'red', 'warning' => 'yellow', 'info' => 'gray'][$f['severity']];
                $this->line(sprintf('  <fg=%s;options=bold>%s</> %s', $color, strtoupper($f['severity']), $f['message']));
                $this->line('    <fg=gray>'.$f['file'].'</>');
                $this->line('    -> '.$f['suggestion']);
            }
        }

        if ($report['unparsed'] !== []) {
            $this->newLine();
            $this->components->warn(count($report['unparsed']).' file(s) could not be parsed and were skipped: '.implode(', ', $report['unparsed']));
        }

        $this->newLine();
    }
}

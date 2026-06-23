<?php

namespace EmilienKopp\LaravelDepth\Commands;

use EmilienKopp\LaravelDepth\Core\ClassMapBuilder;
use EmilienKopp\LaravelDepth\Core\DependencyIndexBuilder;
use EmilienKopp\LaravelDepth\Core\DependencyTracer;
use EmilienKopp\LaravelDepth\Enrichers\RouteEnricher;
use EmilienKopp\LaravelDepth\Output\JsonFormatter;
use EmilienKopp\LaravelDepth\Output\TreeFormatter;
use Illuminate\Console\Command;

class TraceCommand extends Command
{
    protected $signature = 'depth:trace
        {suffix : Class name suffix to trace (e.g. QueryService, Repository, Factory)}
        {--json : Output as JSON instead of a human-readable tree}
        {--output= : Write output to this file path instead of stdout}';

    protected $description = 'Trace dependency trees for all classes matching a given suffix';

    public function handle(): int
    {
        $suffix = (string) $this->argument('suffix');
        $config = config('depth', []);

        $basePath = base_path();
        $scanDirs = $config['scan_directories'] ?? ['Modules', 'app'];
        $excludedPaths = $config['excluded_paths'] ?? [];
        $entryPointSuffixes = $config['entry_point_suffixes'] ?? ['Controller', 'Job', 'Command', 'Listener', 'Webhook'];

        // --- Phase 1: Build class map ---
        $this->stderr('Scanning directories: ' . implode(', ', $scanDirs));

        $classMapBuilder = new ClassMapBuilder($basePath, $scanDirs, $excludedPaths);
        $mapResult = $classMapBuilder->build(function (string $filePath) {
            $this->stderr("  scan: {$filePath}");
        });

        $classMap = $mapResult['classMap'];
        $interfaces = $mapResult['interfaces'];
        $abstracts = $mapResult['abstracts'];

        $count = count($classMap);
        $this->stderr("Found {$count} class(es)/interface(s).");

        // --- Phase 2: Build reverse dependency index ---
        $this->stderr('Building dependency index...');

        $indexBuilder = new DependencyIndexBuilder();
        $reverseIndex = $indexBuilder->build($classMap, function (string $filePath) {
            $this->stderr("  index: {$filePath}");
        });

        // --- Phase 3: Trace callers ---
        $this->stderr("Tracing suffix: {$suffix}");

        $tracer = new DependencyTracer($classMap, $reverseIndex, [
            'entry_point_suffixes' => $entryPointSuffixes,
            'interfaces' => $interfaces,
            'abstracts' => $abstracts,
        ]);

        $result = $tracer->trace($suffix);

        $rootCount = count($result['trees']) + count($result['orphans']);
        if ($rootCount === 0) {
            $this->stderr("No concrete classes found matching suffix: {$suffix}");

            return self::FAILURE;
        }

        $this->stderr(
            'Found ' . count($result['trees']) . ' tree(s) and ' . count($result['orphans']) . ' orphan(s).'
        );

        // --- Phase 4: Enrich entry points with route info ---
        $enricher = new RouteEnricher();
        $routeMap = $enricher->buildRouteMap();

        // --- Phase 5: Format and emit output ---
        if ($this->option('json')) {
            $formatter = new JsonFormatter();
        } else {
            $formatter = new TreeFormatter();
        }

        $output = $formatter->format($result, $routeMap);

        $outputPath = $this->option('output');
        if ($outputPath) {
            $dir = dirname((string) $outputPath);
            if ($dir !== '' && ! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents((string) $outputPath, $output);
            $this->stderr("Output written to: {$outputPath}");
        } else {
            // Write main output to stdout only
            $this->getOutput()->write($output);
        }

        return self::SUCCESS;
    }

    /** Write a progress message to stderr only. */
    private function stderr(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }
}

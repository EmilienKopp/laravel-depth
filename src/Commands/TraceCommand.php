<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Commands;

use EmilienKopp\LaravelDepth\Core\ClassMapBuilder;
use EmilienKopp\LaravelDepth\Core\DependencyIndexBuilder;
use EmilienKopp\LaravelDepth\Core\DependencyTracer;
use EmilienKopp\LaravelDepth\Core\UsageIndexBuilder;
use EmilienKopp\LaravelDepth\Enrichers\RouteEnricher;
use EmilienKopp\LaravelDepth\Output\JsonFormatter;
use EmilienKopp\LaravelDepth\Output\TreeFormatter;
use Illuminate\Console\Command;

final class TraceCommand extends Command
{
    protected $signature = 'depth:trace
        {suffix : Class name suffix to trace (e.g. QueryService, Repository, Factory)}
        {--grep= : Keep only roots whose source file contains this plain string}
        {--uses=* : Keep only roots that use these symbols (trait, extends/implements, property types, method param types)}
        {--json : Output as JSON instead of a human-readable tree}
        {--output= : Write output to this file path instead of stdout}';

    protected $description = 'Trace dependency trees for all classes matching a given suffix';

    private bool $isVerbose = false;

    public function handle(): int
    {
        $suffix = (string) $this->argument('suffix');
        $grepNeedle = $this->normalizeOptionalString($this->option('grep'));
        $usesFilters = $this->normalizeUsesFilters((array) $this->option('uses'));
        $config = config('depth', []);
        $this->isVerbose = $this->option('verbose') || ($config['verbose'] ?? false);

        $basePath = base_path();
        $scanDirs = $config['scan_directories'] ?? ['Modules', 'app'];
        $excludedPaths = $config['excluded_paths'] ?? [];
        $entryPointSuffixes = $config['entry_point_suffixes'] ?? ['Controller', 'Job', 'Command', 'Listener', 'Webhook'];

        // --- Phase 1: Build class map ---
        $this->stderr('Scanning directories: '.implode(', ', $scanDirs));

        $classMapBuilder = new ClassMapBuilder($basePath, $scanDirs, $excludedPaths);
        $mapResult = $classMapBuilder->build(function (string $filePath): void {
            if (! $this->isVerbose) {
                return;
            }

            $this->stderr('  scan: '.$filePath);
        });

        $classMap = $mapResult['classMap'];
        $interfaces = $mapResult['interfaces'];
        $abstracts = $mapResult['abstracts'];

        $count = count($classMap);
        $this->stderr(sprintf('Found %d class(es)/interface(s).', $count));

        // --- Phase 2: Build reverse dependency index ---
        $this->stderr('Building dependency index...');

        $indexBuilder = new DependencyIndexBuilder();
        $reverseIndex = $indexBuilder->build($classMap, function (string $filePath): void {
            if (! $this->isVerbose) {
                return;
            }

            $this->stderr('  index: '.$filePath);
        });

        $usageIndex = [];
        if ($usesFilters !== []) {
            $this->stderr('Building usage index...');

            $usageBuilder = new UsageIndexBuilder();
            $usageIndex = $usageBuilder->build($classMap, function (string $filePath): void {
                if (! $this->isVerbose) {
                    return;
                }

                $this->stderr('  uses: '.$filePath);
            });
        }

        // --- Phase 3: Trace callers ---
        $this->stderr('Tracing suffix: '.$suffix);

        $tracer = new DependencyTracer($classMap, $reverseIndex, [
            'entry_point_suffixes' => $entryPointSuffixes,
            'interfaces' => $interfaces,
            'abstracts' => $abstracts,
            'root_filter' => $this->buildRootFilter($classMap, $grepNeedle, $usesFilters, $usageIndex),
        ]);

        $result = $tracer->trace($suffix);

        $rootCount = count($result['trees']) + count($result['orphans']);
        if ($rootCount === 0) {
            $this->stderr('No concrete classes found matching suffix: '.$suffix);

            return self::FAILURE;
        }

        $this->stderr(
            'Found '.count($result['trees']).' tree(s) and '.count($result['orphans']).' orphan(s).'
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
            $this->stderr('Output written to: '.$outputPath);
        } else {
            // Write main output to stdout only
            $this->getOutput()->write($output);
        }

        return self::SUCCESS;
    }

    /** Write a progress message to stderr only. */
    private function stderr(string $message): void
    {
        if (! $this->isVerbose) {
            return;
        }

        fwrite(STDERR, $message.PHP_EOL);
    }

    /**
     * @param  array<string, string>  $classMap
     * @param  list<string>  $usesFilters
     * @param  array<string, array<string, true>>  $usageIndex
     */
    private function buildRootFilter(
        array $classMap,
        ?string $grepNeedle,
        array $usesFilters,
        array $usageIndex,
    ): ?callable {
        if ($grepNeedle === null && $usesFilters === []) {
            return null;
        }

        return function (string $fqcn) use ($classMap, $grepNeedle, $usesFilters, $usageIndex): bool {
            if ($grepNeedle !== null) {
                $filePath = $classMap[$fqcn] ?? null;
                if (! is_string($filePath)) {
                    return false;
                }

                $code = @file_get_contents($filePath);
                if (! is_string($code) || ! str_contains($code, $grepNeedle)) {
                    return false;
                }
            }

            if ($usesFilters !== []) {
                $symbols = $usageIndex[$fqcn] ?? [];
                foreach ($usesFilters as $filter) {
                    if (! $this->hasMatchingUsage($symbols, $filter)) {
                        return false;
                    }
                }
            }

            return true;
        };
    }

    /** @param  list<string>  $rawFilters */
    private function normalizeUsesFilters(array $rawFilters): array
    {
        $normalized = [];

        foreach ($rawFilters as $rawFilter) {
            $chunks = explode(',', (string) $rawFilter);
            foreach ($chunks as $chunk) {
                $value = mb_trim($chunk);
                if ($value !== '') {
                    $normalized[] = mb_ltrim($value, '\\');
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = mb_trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /** @param  array<string, true>  $symbols */
    private function hasMatchingUsage(array $symbols, string $filter): bool
    {
        $needle = mb_strtolower(mb_ltrim($filter, '\\'));
        foreach (array_keys($symbols) as $symbol) {
            $candidate = mb_strtolower(mb_ltrim($symbol, '\\'));
            if ($candidate === $needle) {
                return true;
            }

            if (str_ends_with($candidate, '\\'.$needle)) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Core;

use Closure;

/**
 * Traces the caller graph for all classes matching a given suffix.
 *
 * Starting from root targets (concrete, non-interface classes whose name ends
 * with the suffix), the tracer walks the reverse dependency index recursively
 * to build a tree of callers. Cycle detection prevents infinite loops.
 *
 * Classes with no callers are marked as orphans.
 * Classes whose name ends with an entry-point suffix stop recursion and are
 * marked as entry points.
 */
final class DependencyTracer
{
    /** @var list<string> */
    private readonly array $entryPointSuffixes;

    /** @var array<string, true> */
    private readonly array $interfaces;

    /** @var array<string, true> */
    private readonly array $abstracts;

    /** @var Closure(string): bool|null */
    private readonly ?Closure $rootFilter;

    /**
     * @param  array<string, string>  $classMap  FQCN => file path
     * @param  array<string, list<string>>  $reverseIndex  injected FQCN => [callers]
     * @param  array{
     *   entry_point_suffixes?: list<string>,
     *   interfaces?: array<string, true>,
     *   abstracts?: array<string, true>,
     *   root_filter?: callable(string): bool
     * }  $options
     */
    public function __construct(
        private readonly array $classMap,
        private readonly array $reverseIndex,
        array $options = [],
    ) {
        $this->entryPointSuffixes = $options['entry_point_suffixes']
            ?? ['Controller', 'Job', 'Command', 'Listener', 'Webhook'];
        $this->interfaces = $options['interfaces'] ?? [];
        $this->abstracts = $options['abstracts'] ?? [];
        $rootFilter = $options['root_filter'] ?? null;
        $this->rootFilter = is_callable($rootFilter) ? Closure::fromCallable($rootFilter) : null;
    }

    /**
     * Trace callers of every concrete class whose name ends with $suffix.
     *
     * @return array{
     *   trees: array<string, array>,
     *   orphans: list<string>
     * }
     */
    public function trace(string $suffix): array
    {
        $roots = $this->findRoots($suffix);
        $trees = [];
        $orphans = [];

        foreach ($roots as $root) {
            $callers = $this->reverseIndex[$root] ?? [];
            if (empty($callers)) {
                $orphans[] = $root;
            } else {
                $trees[$root] = $this->buildTree($root, [$root]);
            }
        }

        return ['trees' => $trees, 'orphans' => $orphans];
    }

    /** @return list<string> */
    public function getEntryPointSuffixes(): array
    {
        return $this->entryPointSuffixes;
    }

    /**
     * Find all concrete (non-interface, non-abstract) classes ending with $suffix.
     *
     * @return list<string>
     */
    private function findRoots(string $suffix): array
    {
        $roots = [];
        foreach (array_keys($this->classMap) as $fqcn) {
            if (! str_ends_with($fqcn, $suffix)) {
                continue;
            }

            if (isset($this->interfaces[$fqcn])) {
                continue;
            }

            if (isset($this->abstracts[$fqcn])) {
                continue;
            }

            if ($this->rootFilter instanceof Closure && ! ($this->rootFilter)($fqcn)) {
                continue;
            }

            $roots[] = $fqcn;
        }

        sort($roots);

        return $roots;
    }

    /**
     * Recursively build a caller tree for $fqcn.
     *
     * @param  list<string>  $visited  FQCNs already on this branch (cycle guard)
     * @return array{callers: array<string, array>}
     */
    private function buildTree(string $fqcn, array $visited): array
    {
        $callers = $this->reverseIndex[$fqcn] ?? [];
        $node = ['callers' => []];

        foreach ($callers as $caller) {
            if (in_array($caller, $visited, true)) {
                $node['callers'][$caller] = ['cycle' => true];

                continue;
            }

            if ($this->isEntryPoint($caller)) {
                $node['callers'][$caller] = ['entry' => true];
            } else {
                $subtree = $this->buildTree($caller, [...$visited, $caller]);
                $node['callers'][$caller] = $subtree;
            }
        }

        return $node;
    }

    private function isEntryPoint(string $fqcn): bool
    {
        foreach ($this->entryPointSuffixes as $suffix) {
            if (str_ends_with($fqcn, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

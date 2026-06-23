<?php

namespace EmilienKopp\LaravelDepth\Core;

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
class DependencyTracer
{
    /** @var list<string> */
    private readonly array $entryPointSuffixes;
    /** @var array<string, true> */
    private readonly array $interfaces;
    /** @var array<string, true> */
    private readonly array $abstracts;

    /**
     * @param  array<string, string>  $classMap  FQCN => file path
     * @param  array<string, list<string>>  $reverseIndex  injected FQCN => [callers]
     * @param  array{
     *   entry_point_suffixes?: list<string>,
     *   interfaces?: array<string, true>,
     *   abstracts?: array<string, true>
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

        return compact('trees', 'orphans');
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
            if (isset($this->interfaces[$fqcn]) || isset($this->abstracts[$fqcn])) {
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

    /** @return list<string> */
    public function getEntryPointSuffixes(): array
    {
        return $this->entryPointSuffixes;
    }
}

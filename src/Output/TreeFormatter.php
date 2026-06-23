<?php

namespace EmilienKopp\LaravelDepth\Output;

/**
 * Formats the dependency trace result as a human-readable indented tree.
 *
 * Example output:
 *
 *   Modules\Foo\Infrastructure\QueryService\FooQueryService
 *       └── Modules\Foo\Application\UseCase\FooUseCase
 *           └── Modules\Foo\Presentation\Controllers\FooController [ENTRY: GET api/foo → auth]
 *
 *   ⚠  ORPHAN (nothing calls this): Modules\Foo\Infrastructure\QueryService\OrphanQueryService
 */
class TreeFormatter
{
    /**
     * Format the trace result as a human-readable tree string.
     *
     * @param  array{trees: array<string, array>, orphans: list<string>}  $result
     * @param  array<string, array{method: string, route: string, middlewares: list<string>}>  $routeMap
     */
    public function format(array $result, array $routeMap = []): string
    {
        $output = '';

        foreach ($result['trees'] as $root => $tree) {
            $output .= $root . "\n";
            $output .= $this->formatNode($tree, '    ', $routeMap);
            $output .= "\n";
        }

        foreach ($result['orphans'] as $orphan) {
            $output .= "⚠  ORPHAN (nothing calls this): {$orphan}\n";
        }

        return $output;
    }

    private function formatNode(array $node, string $indent, array $routeMap): string
    {
        $output = '';

        foreach ($node['callers'] ?? [] as $caller => $callerNode) {
            if (isset($callerNode['cycle']) && $callerNode['cycle']) {
                $output .= "{$indent}└── {$caller} [CYCLE]\n";
                continue;
            }

            if (isset($callerNode['entry']) && $callerNode['entry']) {
                $entryInfo = $this->buildEntryAnnotation($caller, $routeMap);
                $output .= "{$indent}└── {$caller}{$entryInfo}\n";
            } else {
                $output .= "{$indent}└── {$caller}\n";
                if (! empty($callerNode['callers'])) {
                    $output .= $this->formatNode($callerNode, $indent . '    ', $routeMap);
                }
            }
        }

        return $output;
    }

    private function buildEntryAnnotation(string $caller, array $routeMap): string
    {
        if (! isset($routeMap[$caller])) {
            return ' [ENTRY]';
        }

        $route = $routeMap[$caller];
        $middlewares = implode(', ', $route['middlewares']);
        $arrow = $middlewares !== '' ? " → {$middlewares}" : '';

        return " [ENTRY: {$route['method']} {$route['route']}{$arrow}]";
    }
}

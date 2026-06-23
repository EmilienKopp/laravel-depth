<?php

namespace EmilienKopp\LaravelDepth\Output;

/**
 * Formats the dependency trace result as JSON.
 *
 * Each root class is a top-level key whose value contains a "callers" map.
 * Entry-point nodes carry "entry": true plus optional route/middleware data.
 * Orphan classes carry "orphan": true.
 *
 * Example:
 * {
 *   "Modules\\Foo\\Infrastructure\\QueryService\\FooQueryService": {
 *     "callers": {
 *       "Modules\\Foo\\Application\\UseCase\\FooUseCase": {
 *         "callers": {
 *           "Modules\\Foo\\Presentation\\Controllers\\FooController": {
 *             "entry": true,
 *             "method": "GET",
 *             "route": "api/foo",
 *             "middlewares": ["api", "auth"]
 *           }
 *         }
 *       }
 *     }
 *   },
 *   "Modules\\Foo\\Infrastructure\\QueryService\\OrphanQueryService": {
 *     "orphan": true
 *   }
 * }
 */
class JsonFormatter
{
    /**
     * Format the trace result as a JSON string.
     *
     * @param  array{trees: array<string, array>, orphans: list<string>}  $result
     * @param  array<string, array{method: string, route: string, middlewares: list<string>}>  $routeMap
     */
    public function format(array $result, array $routeMap = []): string
    {
        $output = [];

        foreach ($result['trees'] as $root => $tree) {
            $output[$root] = $this->formatNode($tree, $routeMap);
        }

        foreach ($result['orphans'] as $orphan) {
            $output[$orphan] = ['orphan' => true];
        }

        return json_encode(
            $output,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
    }

    private function formatNode(array $node, array $routeMap): array
    {
        $callers = [];

        foreach ($node['callers'] ?? [] as $caller => $callerNode) {
            if (isset($callerNode['cycle']) && $callerNode['cycle']) {
                $callers[$caller] = ['cycle' => true];
                continue;
            }

            if (isset($callerNode['entry']) && $callerNode['entry']) {
                $entryData = ['entry' => true];
                if (isset($routeMap[$caller])) {
                    $route = $routeMap[$caller];
                    $entryData['method'] = $route['method'];
                    $entryData['route'] = $route['route'];
                    $entryData['middlewares'] = $route['middlewares'];
                }
                $callers[$caller] = $entryData;
            } else {
                $callers[$caller] = $this->formatNode($callerNode, $routeMap);
            }
        }

        return ['callers' => $callers];
    }
}

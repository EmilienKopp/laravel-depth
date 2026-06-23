<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Enrichers;

use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Enriches entry-point Controller nodes with their fully resolved
 * HTTP method, route URI, and middleware stack using Laravel's Route collection.
 */
final class RouteEnricher
{
    /** @var array<string, array{method: string, route: string, middlewares: list<string>}>|null */
    private ?array $routeMap = null;

    /**
     * Build a map of controller FQCN => route info.
     *
     * @return array<string, array{method: string, route: string, middlewares: list<string>}>
     */
    public function buildRouteMap(): array
    {
        if ($this->routeMap !== null) {
            return $this->routeMap;
        }

        $this->routeMap = [];

        try {
            $routeCollection = Route::getRoutes();
            /** @var list<\Illuminate\Routing\Route> $routes */
            $routes = array_values($routeCollection->getRoutes());
        } catch (Throwable) {
            return $this->routeMap;
        }

        foreach ($routes as $route) {
            try {
                $action = $route->getAction();
                $controller = $action['controller'] ?? $action['uses'] ?? null;

                if (! is_string($controller)) {
                    continue;
                }

                // Handle "ClassName@method" and invokable "ClassName"
                $class = str_contains($controller, '@')
                    ? explode('@', $controller)[0]
                    : $controller;

                $class = mb_ltrim($class, '\\');

                // Collect HTTP methods, omitting HEAD for cleaner output
                $methods = array_filter($route->methods(), fn (string $m): bool => $m !== 'HEAD');
                $method = implode('|', array_values($methods));

                $this->routeMap[$class] = [
                    'method' => $method,
                    'route' => $route->uri(),
                    'middlewares' => $route->gatherMiddleware(),
                ];
            } catch (Throwable) {
                // Skip problematic routes
            }
        }

        return $this->routeMap;
    }

    /**
     * Return route info for a specific controller FQCN, or null if not found.
     *
     * @return array{method: string, route: string, middlewares: list<string>}|null
     */
    public function enrich(string $fqcn): ?array
    {
        return $this->buildRouteMap()[$fqcn] ?? null;
    }
}

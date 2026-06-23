# Laravel Depth

A static analysis package for Laravel that traces dependency caller trees and helps estimate the blast radius of refactors.

Given a class name suffix (for example `QueryService`), Laravel Depth scans your codebase, finds matching concrete classes, and walks backward through constructor injection to show who depends on them.

## What it does

- Scans configured directories for PHP classes with `nikic/php-parser`.
- Builds a reverse dependency index from constructor type hints.
- Traces caller trees for every concrete class matching a suffix.
- Stops traversal at configurable entry points (for example `Controller`, `Job`, `Command`).
- Marks cycles to avoid infinite recursion.
- Flags classes with no callers as orphans.
- Enriches controller entry points with route method, URI, and middleware (when available).
- Outputs either human-readable tree text or JSON.

## Requirements

- PHP `^8.1`
- Laravel components `^10.0|^11.0`

## Installation

Install with Composer:

```bash
composer require emilienkopp/laravel-depth
```

Publish the configuration file (optional, but recommended):

```bash
php artisan vendor:publish --tag=depth-config
```

## Quick start

Trace all concrete classes ending with `QueryService`:

```bash
php artisan depth:trace QueryService
```

JSON output:

```bash
php artisan depth:trace QueryService --json
```

Write output to a file:

```bash
php artisan depth:trace QueryService --output=storage/app/depth-queryservice.txt
```

The command exits with:

- `0` on success
- `1` when no matching concrete classes are found

## Command reference

`depth:trace {suffix} {--json} {--output=}`

- `suffix` (required): class-name suffix to trace, such as `Repository`, `Factory`, `QueryService`
- `--json`: return machine-readable JSON instead of tree text
- `--output=...`: write output to a file path instead of stdout (directories are created if needed)

Progress information is written to stderr, while the formatted result is written to stdout (unless `--output` is used).

## Configuration

Published config file: `config/depth.php`

```php
return [
 'scan_directories' => [
  'Modules',
  'app',
 ],

 'excluded_paths' => [
  'vendor',
 ],

 'entry_point_suffixes' => [
  'Controller',
  'Job',
  'Command',
  'Listener',
  'Webhook',
 ],
];
```

### scan_directories

Directories (relative to `base_path()`) to scan for PHP files.

### excluded_paths

Path segments to skip while scanning.

### entry_point_suffixes

Class-name suffixes that stop recursion and are marked as entry points in output.

## Example output (tree)

```text
App\Services\FooQueryService
 └── App\UseCases\FooUseCase
  └── App\Http\Controllers\FooController [ENTRY: GET api/foo → api, auth]

⚠  ORPHAN (nothing calls this): App\Services\OrphanQueryService
```

Legend:

- `[ENTRY]`: traversal stops at this node because it matches an entry-point suffix
- `[ENTRY: ...]`: entry node enriched with route and middleware metadata
- `[CYCLE]`: cycle detected on the current branch
- `ORPHAN`: matching root class has no callers

## Example output (JSON)

```json
{
 "App\\Services\\FooQueryService": {
  "callers": {
   "App\\UseCases\\FooUseCase": {
    "callers": {
     "App\\Http\\Controllers\\FooController": {
      "entry": true,
      "method": "GET",
      "route": "api/foo",
      "middlewares": [
       "api",
       "auth"
      ]
     }
    }
   }
  }
 },
 "App\\Services\\OrphanQueryService": {
  "orphan": true
 }
}
```

## How dependency detection works

Laravel Depth is static analysis based. It currently indexes dependencies from constructor injection type hints and then builds a reverse graph:

`injected class => [callers]`

It then starts from roots matching your suffix and recursively walks callers upward.

## Notes and limitations

- Only concrete classes are considered roots (interfaces and abstract classes are excluded).
- Dependency discovery is based on constructor signatures, not runtime container behavior.
- Route enrichment applies to controller entry points when Laravel routes can be resolved.
- Files that cannot be parsed are skipped silently.

## Testing

Run the test suite:

```bash
vendor/bin/phpunit
```

## License

MIT. See `LICENSE`.

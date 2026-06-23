<?php

declare(strict_types=1);

use EmilienKopp\LaravelDepth\Core\DependencyTracer;

beforeEach(function (): void {
    $this->classMap = [
        'App\\Services\\FooQueryService' => '/path/FooQueryService.php',
        'App\\Services\\BarQueryService' => '/path/BarQueryService.php',
        'App\\Services\\SomeOtherService' => '/path/SomeOtherService.php',
        'App\\UseCases\\FooUseCase' => '/path/FooUseCase.php',
        'App\\Http\\Controllers\\FooController' => '/path/FooController.php',
        'App\\Contracts\\IFooQueryService' => '/path/IFooQueryService.php',
    ];
});

test('finds roots by suffix and traces callers', function (): void {
    $reverseIndex = [
        'App\\Services\\FooQueryService' => ['App\\UseCases\\FooUseCase'],
        'App\\UseCases\\FooUseCase' => ['App\\Http\\Controllers\\FooController'],
    ];

    $tracer = new DependencyTracer($this->classMap, $reverseIndex, [
        'entry_point_suffixes' => ['Controller'],
    ]);

    $result = $tracer->trace('QueryService');

    expect($result['trees'])->toHaveKey('App\\Services\\FooQueryService');
    expect($result['orphans'])->toContain('App\\Services\\BarQueryService');
    expect($result['trees'])->not->toHaveKey('App\\Services\\SomeOtherService');
    expect($result['orphans'])->not->toContain('App\\Services\\SomeOtherService');
});

test('marks orphans when no callers', function (): void {
    $tracer = new DependencyTracer(
        ['App\\Services\\OrphanQueryService' => '/path/Orphan.php'],
        [],
        ['entry_point_suffixes' => ['Controller']]
    );

    $result = $tracer->trace('QueryService');

    expect($result['orphans'])->toContain('App\\Services\\OrphanQueryService');
    expect($result['trees'])->toBeEmpty();
});

test('detects cycles and does not loop', function (): void {
    $classMap = [
        'App\\Services\\FooQueryService' => '/path/Foo.php',
        'App\\Services\\BarService' => '/path/Bar.php',
    ];

    $reverseIndex = [
        'App\\Services\\FooQueryService' => ['App\\Services\\BarService'],
        'App\\Services\\BarService' => ['App\\Services\\FooQueryService'],
    ];

    $tracer = new DependencyTracer($classMap, $reverseIndex, [
        'entry_point_suffixes' => ['Controller'],
    ]);

    $result = $tracer->trace('QueryService');

    expect($result['trees'])->toHaveKey('App\\Services\\FooQueryService');

    $tree = $result['trees']['App\\Services\\FooQueryService'];
    $barNode = $tree['callers']['App\\Services\\BarService'];
    expect($barNode['callers'])->toHaveKey('App\\Services\\FooQueryService');
    expect($barNode['callers']['App\\Services\\FooQueryService']['cycle'])->toBeTrue();
});

test('excludes interfaces from roots', function (): void {
    $tracer = new DependencyTracer($this->classMap, [], [
        'entry_point_suffixes' => ['Controller'],
        'interfaces' => ['App\\Contracts\\IFooQueryService' => true],
    ]);

    $result = $tracer->trace('QueryService');

    expect($result['orphans'])->not->toContain('App\\Contracts\\IFooQueryService');
    expect($result['trees'])->not->toHaveKey('App\\Contracts\\IFooQueryService');
});

test('excludes abstract classes from roots', function (): void {
    $classMap = [
        'App\\Services\\AbstractQueryService' => '/path/Abstract.php',
        'App\\Services\\ConcreteQueryService' => '/path/Concrete.php',
    ];

    $tracer = new DependencyTracer($classMap, [], [
        'entry_point_suffixes' => ['Controller'],
        'abstracts' => ['App\\Services\\AbstractQueryService' => true],
    ]);

    $result = $tracer->trace('QueryService');

    expect($result['orphans'])->not->toContain('App\\Services\\AbstractQueryService');
    expect($result['orphans'])->toContain('App\\Services\\ConcreteQueryService');
});

test('entry points stop recursion', function (): void {
    $reverseIndex = [
        'App\\Services\\FooQueryService' => ['App\\Http\\Controllers\\FooController'],
        'App\\Http\\Controllers\\FooController' => ['App\\Services\\SomeMiddleware'],
    ];

    $tracer = new DependencyTracer($this->classMap, $reverseIndex, [
        'entry_point_suffixes' => ['Controller'],
    ]);

    $result = $tracer->trace('QueryService');

    $tree = $result['trees']['App\\Services\\FooQueryService'];
    $controllerNode = $tree['callers']['App\\Http\\Controllers\\FooController'];

    expect($controllerNode['entry'])->toBeTrue();
    expect($controllerNode)->not->toHaveKey('callers');
});

test('returns empty when no matching classes', function (): void {
    $tracer = new DependencyTracer($this->classMap, [], [
        'entry_point_suffixes' => ['Controller'],
    ]);

    $result = $tracer->trace('NonExistentSuffix');

    expect($result['trees'])->toBeEmpty();
    expect($result['orphans'])->toBeEmpty();
});

test('root filter can include only matching roots', function (): void {
    $reverseIndex = [
        'App\\Services\\FooQueryService' => ['App\\UseCases\\FooUseCase'],
    ];

    $tracer = new DependencyTracer($this->classMap, $reverseIndex, [
        'entry_point_suffixes' => ['Controller'],
        'root_filter' => fn (string $fqcn): bool => str_contains($fqcn, 'Foo'),
    ]);

    $result = $tracer->trace('QueryService');

    expect($result['trees'])->toHaveKey('App\\Services\\FooQueryService');
    expect($result['orphans'])->not->toContain('App\\Services\\BarQueryService');
});

test('root filter can exclude all roots', function (): void {
    $tracer = new DependencyTracer($this->classMap, [], [
        'entry_point_suffixes' => ['Controller'],
        'root_filter' => fn (string $fqcn): bool => false,
    ]);

    $result = $tracer->trace('QueryService');

    expect($result['trees'])->toBeEmpty();
    expect($result['orphans'])->toBeEmpty();
});

<?php

namespace EmilienKopp\LaravelDepth\Tests\Unit;

use EmilienKopp\LaravelDepth\Core\DependencyTracer;
use PHPUnit\Framework\TestCase;

class DependencyTracerTest extends TestCase
{
    private array $classMap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classMap = [
            'App\\Services\\FooQueryService' => '/path/FooQueryService.php',
            'App\\Services\\BarQueryService' => '/path/BarQueryService.php',
            'App\\Services\\SomeOtherService' => '/path/SomeOtherService.php',
            'App\\UseCases\\FooUseCase' => '/path/FooUseCase.php',
            'App\\Http\\Controllers\\FooController' => '/path/FooController.php',
            'App\\Contracts\\IFooQueryService' => '/path/IFooQueryService.php',
        ];
    }

    public function test_finds_roots_by_suffix_and_traces_callers(): void
    {
        $reverseIndex = [
            'App\\Services\\FooQueryService' => ['App\\UseCases\\FooUseCase'],
            'App\\UseCases\\FooUseCase' => ['App\\Http\\Controllers\\FooController'],
        ];

        $tracer = new DependencyTracer($this->classMap, $reverseIndex, [
            'entry_point_suffixes' => ['Controller'],
        ]);

        $result = $tracer->trace('QueryService');

        // FooQueryService has callers → appears in trees
        $this->assertArrayHasKey('App\\Services\\FooQueryService', $result['trees']);

        // BarQueryService has no callers → orphan
        $this->assertContains('App\\Services\\BarQueryService', $result['orphans']);

        // SomeOtherService doesn't match suffix → not in trees or orphans
        $this->assertArrayNotHasKey('App\\Services\\SomeOtherService', $result['trees']);
        $this->assertNotContains('App\\Services\\SomeOtherService', $result['orphans']);
    }

    public function test_marks_orphans_when_no_callers(): void
    {
        $tracer = new DependencyTracer(
            ['App\\Services\\OrphanQueryService' => '/path/Orphan.php'],
            [],
            ['entry_point_suffixes' => ['Controller']]
        );

        $result = $tracer->trace('QueryService');

        $this->assertContains('App\\Services\\OrphanQueryService', $result['orphans']);
        $this->assertEmpty($result['trees']);
    }

    public function test_detects_cycles_and_does_not_loop(): void
    {
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

        // Must not throw or loop infinitely
        $result = $tracer->trace('QueryService');

        $this->assertArrayHasKey('App\\Services\\FooQueryService', $result['trees']);

        // The cycle should be detected in the callers tree
        $tree = $result['trees']['App\\Services\\FooQueryService'];
        $barNode = $tree['callers']['App\\Services\\BarService'];
        $this->assertArrayHasKey('App\\Services\\FooQueryService', $barNode['callers']);
        $this->assertTrue($barNode['callers']['App\\Services\\FooQueryService']['cycle']);
    }

    public function test_excludes_interfaces_from_roots(): void
    {
        $tracer = new DependencyTracer($this->classMap, [], [
            'entry_point_suffixes' => ['Controller'],
            'interfaces' => ['App\\Contracts\\IFooQueryService' => true],
        ]);

        $result = $tracer->trace('QueryService');

        // Interface should NOT appear as root (not in orphans or trees)
        $this->assertNotContains('App\\Contracts\\IFooQueryService', $result['orphans']);
        $this->assertArrayNotHasKey('App\\Contracts\\IFooQueryService', $result['trees']);
    }

    public function test_excludes_abstract_classes_from_roots(): void
    {
        $classMap = [
            'App\\Services\\AbstractQueryService' => '/path/Abstract.php',
            'App\\Services\\ConcreteQueryService' => '/path/Concrete.php',
        ];

        $tracer = new DependencyTracer($classMap, [], [
            'entry_point_suffixes' => ['Controller'],
            'abstracts' => ['App\\Services\\AbstractQueryService' => true],
        ]);

        $result = $tracer->trace('QueryService');

        $this->assertNotContains('App\\Services\\AbstractQueryService', $result['orphans']);
        $this->assertContains('App\\Services\\ConcreteQueryService', $result['orphans']);
    }

    public function test_entry_points_stop_recursion(): void
    {
        $reverseIndex = [
            'App\\Services\\FooQueryService' => ['App\\Http\\Controllers\\FooController'],
            // Controller has its own callers but tracing should stop at entry point
            'App\\Http\\Controllers\\FooController' => ['App\\Services\\SomeMiddleware'],
        ];

        $tracer = new DependencyTracer($this->classMap, $reverseIndex, [
            'entry_point_suffixes' => ['Controller'],
        ]);

        $result = $tracer->trace('QueryService');

        $tree = $result['trees']['App\\Services\\FooQueryService'];
        $controllerNode = $tree['callers']['App\\Http\\Controllers\\FooController'];

        // Controller node is marked as entry
        $this->assertTrue($controllerNode['entry']);
        // Recursion stopped — no 'callers' key inside the entry node
        $this->assertArrayNotHasKey('callers', $controllerNode);
    }

    public function test_returns_empty_when_no_matching_classes(): void
    {
        $tracer = new DependencyTracer($this->classMap, [], [
            'entry_point_suffixes' => ['Controller'],
        ]);

        $result = $tracer->trace('NonExistentSuffix');

        $this->assertEmpty($result['trees']);
        $this->assertEmpty($result['orphans']);
    }
}

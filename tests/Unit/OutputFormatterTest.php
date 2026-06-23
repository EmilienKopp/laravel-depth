<?php

namespace EmilienKopp\LaravelDepth\Tests\Unit;

use EmilienKopp\LaravelDepth\Output\JsonFormatter;
use EmilienKopp\LaravelDepth\Output\TreeFormatter;
use PHPUnit\Framework\TestCase;

class OutputFormatterTest extends TestCase
{
    private array $result;
    private array $routeMap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->result = [
            'trees' => [
                'App\\Services\\FooQueryService' => [
                    'callers' => [
                        'App\\UseCases\\FooUseCase' => [
                            'callers' => [
                                'App\\Http\\Controllers\\FooController' => ['entry' => true],
                            ],
                        ],
                    ],
                ],
            ],
            'orphans' => ['App\\Services\\OrphanQueryService'],
        ];

        $this->routeMap = [
            'App\\Http\\Controllers\\FooController' => [
                'method' => 'GET',
                'route' => 'api/foo',
                'middlewares' => ['api', 'auth'],
            ],
        ];
    }

    // --- TreeFormatter ---

    public function test_tree_formatter_renders_root(): void
    {
        $formatter = new TreeFormatter();
        $output = $formatter->format($this->result, $this->routeMap);

        $this->assertStringContainsString('App\\Services\\FooQueryService', $output);
    }

    public function test_tree_formatter_renders_callers_with_tree_chars(): void
    {
        $formatter = new TreeFormatter();
        $output = $formatter->format($this->result, $this->routeMap);

        $this->assertStringContainsString('└──', $output);
        $this->assertStringContainsString('App\\UseCases\\FooUseCase', $output);
    }

    public function test_tree_formatter_annotates_entry_with_route(): void
    {
        $formatter = new TreeFormatter();
        $output = $formatter->format($this->result, $this->routeMap);

        $this->assertStringContainsString('[ENTRY: GET api/foo', $output);
        $this->assertStringContainsString('api, auth', $output);
    }

    public function test_tree_formatter_annotates_entry_without_route(): void
    {
        $formatter = new TreeFormatter();
        $output = $formatter->format($this->result, []); // no route map

        $this->assertStringContainsString('[ENTRY]', $output);
    }

    public function test_tree_formatter_renders_orphan_warning(): void
    {
        $formatter = new TreeFormatter();
        $output = $formatter->format($this->result, []);

        $this->assertStringContainsString('ORPHAN', $output);
        $this->assertStringContainsString('App\\Services\\OrphanQueryService', $output);
    }

    public function test_tree_formatter_marks_cycles(): void
    {
        $result = [
            'trees' => [
                'App\\Services\\FooQueryService' => [
                    'callers' => [
                        'App\\Services\\FooQueryService' => ['cycle' => true],
                    ],
                ],
            ],
            'orphans' => [],
        ];

        $formatter = new TreeFormatter();
        $output = $formatter->format($result, []);

        $this->assertStringContainsString('[CYCLE]', $output);
    }

    // --- JsonFormatter ---

    public function test_json_formatter_produces_valid_json(): void
    {
        $formatter = new JsonFormatter();
        $output = $formatter->format($this->result, $this->routeMap);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertNull(json_last_error() === JSON_ERROR_NONE ? null : 'json error');
    }

    public function test_json_formatter_includes_entry_info(): void
    {
        $formatter = new JsonFormatter();
        $output = $formatter->format($this->result, $this->routeMap);
        $decoded = json_decode($output, true);

        $root = $decoded['App\\Services\\FooQueryService'];
        $useCase = $root['callers']['App\\UseCases\\FooUseCase'];
        $controller = $useCase['callers']['App\\Http\\Controllers\\FooController'];

        $this->assertTrue($controller['entry']);
        $this->assertSame('GET', $controller['method']);
        $this->assertSame('api/foo', $controller['route']);
        $this->assertSame(['api', 'auth'], $controller['middlewares']);
    }

    public function test_json_formatter_includes_orphan_flag(): void
    {
        $formatter = new JsonFormatter();
        $output = $formatter->format($this->result, []);
        $decoded = json_decode($output, true);

        $this->assertArrayHasKey('App\\Services\\OrphanQueryService', $decoded);
        $this->assertTrue($decoded['App\\Services\\OrphanQueryService']['orphan']);
    }

    public function test_json_formatter_marks_cycles(): void
    {
        $result = [
            'trees' => [
                'App\\Services\\FooQueryService' => [
                    'callers' => [
                        'App\\Services\\BarService' => ['cycle' => true],
                    ],
                ],
            ],
            'orphans' => [],
        ];

        $formatter = new JsonFormatter();
        $output = $formatter->format($result, []);
        $decoded = json_decode($output, true);

        $root = $decoded['App\\Services\\FooQueryService'];
        $this->assertTrue($root['callers']['App\\Services\\BarService']['cycle']);
    }
}

<?php

declare(strict_types=1);

use EmilienKopp\LaravelDepth\Output\JsonFormatter;
use EmilienKopp\LaravelDepth\Output\TreeFormatter;

beforeEach(function (): void {
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
});

test('tree formatter renders root', function (): void {
    $formatter = new TreeFormatter();
    $output = $formatter->format($this->result, $this->routeMap);

    expect($output)->toContain('App\\Services\\FooQueryService');
});

test('tree formatter renders callers with tree chars', function (): void {
    $formatter = new TreeFormatter();
    $output = $formatter->format($this->result, $this->routeMap);

    expect($output)->toContain('└──');
    expect($output)->toContain('App\\UseCases\\FooUseCase');
});

test('tree formatter annotates entry with route', function (): void {
    $formatter = new TreeFormatter();
    $output = $formatter->format($this->result, $this->routeMap);

    expect($output)->toContain('[ENTRY: GET api/foo');
    expect($output)->toContain('api, auth');
});

test('tree formatter annotates entry without route', function (): void {
    $formatter = new TreeFormatter();
    $output = $formatter->format($this->result, []);

    expect($output)->toContain('[ENTRY]');
});

test('tree formatter renders orphan warning', function (): void {
    $formatter = new TreeFormatter();
    $output = $formatter->format($this->result, []);

    expect($output)->toContain('ORPHAN');
    expect($output)->toContain('App\\Services\\OrphanQueryService');
});

test('tree formatter marks cycles', function (): void {
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

    expect($output)->toContain('[CYCLE]');
});

test('json formatter produces valid json', function (): void {
    $formatter = new JsonFormatter();
    $output = $formatter->format($this->result, $this->routeMap);

    $decoded = json_decode($output, true);
    expect($decoded)->toBeArray();
    expect(json_last_error())->toBe(JSON_ERROR_NONE);
});

test('json formatter includes entry info', function (): void {
    $formatter = new JsonFormatter();
    $output = $formatter->format($this->result, $this->routeMap);
    $decoded = json_decode($output, true);

    $root = $decoded['App\\Services\\FooQueryService'];
    $useCase = $root['callers']['App\\UseCases\\FooUseCase'];
    $controller = $useCase['callers']['App\\Http\\Controllers\\FooController'];

    expect($controller['entry'])->toBeTrue();
    expect($controller['method'])->toBe('GET');
    expect($controller['route'])->toBe('api/foo');
    expect($controller['middlewares'])->toBe(['api', 'auth']);
});

test('json formatter includes orphan flag', function (): void {
    $formatter = new JsonFormatter();
    $output = $formatter->format($this->result, []);
    $decoded = json_decode($output, true);

    expect($decoded)->toHaveKey('App\\Services\\OrphanQueryService');
    expect($decoded['App\\Services\\OrphanQueryService']['orphan'])->toBeTrue();
});

test('json formatter marks cycles', function (): void {
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
    expect($root['callers']['App\\Services\\BarService']['cycle'])->toBeTrue();
});

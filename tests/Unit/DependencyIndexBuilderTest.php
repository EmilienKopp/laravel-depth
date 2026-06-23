<?php

declare(strict_types=1);

use EmilienKopp\LaravelDepth\Core\DependencyIndexBuilder;

beforeEach(function (): void {
    $this->fixtureDir = sys_get_temp_dir().'/laravel-depth-dep-test-'.uniqid();
    mkdir($this->fixtureDir, 0777, true);
});

afterEach(function (): void {
    $rmdirRecursive = function (string $dir) use (&$rmdirRecursive): void {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.') {
                continue;
            }

            if ($item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;
            is_dir($path) ? $rmdirRecursive($path) : unlink($path);
        }

        rmdir($dir);
    };

    $rmdirRecursive($this->fixtureDir);
});

test('builds reverse index from constructor injection', function (): void {
    $serviceFile = $this->fixtureDir.'/FooService.php';
    file_put_contents($serviceFile, '<?php
namespace App\Services;
use App\Repositories\FooRepository;
class FooService {
    public function __construct(private FooRepository $repo) {}
}
');

    $repoFile = $this->fixtureDir.'/FooRepository.php';
    file_put_contents($repoFile, '<?php
namespace App\Repositories;
class FooRepository {}
');

    $classMap = [
        'App\\Services\\FooService' => $serviceFile,
        'App\\Repositories\\FooRepository' => $repoFile,
    ];

    $builder = new DependencyIndexBuilder();
    $index = $builder->build($classMap);

    expect($index)->toHaveKey('App\\Repositories\\FooRepository');
    expect($index['App\\Repositories\\FooRepository'])->toContain('App\\Services\\FooService');
});

test('handles nullable type hints', function (): void {
    $serviceFile = $this->fixtureDir.'/FooService.php';
    file_put_contents($serviceFile, '<?php
namespace App\Services;
use App\Repositories\FooRepository;
class FooService {
    public function __construct(private ?FooRepository $repo = null) {}
}
');

    $repoFile = $this->fixtureDir.'/FooRepository.php';
    file_put_contents($repoFile, '<?php namespace App\Repositories; class FooRepository {}');

    $classMap = [
        'App\\Services\\FooService' => $serviceFile,
        'App\\Repositories\\FooRepository' => $repoFile,
    ];

    $builder = new DependencyIndexBuilder();
    $index = $builder->build($classMap);

    expect($index)->toHaveKey('App\\Repositories\\FooRepository');
    expect($index['App\\Repositories\\FooRepository'])->toContain('App\\Services\\FooService');
});

test('resolves fully qualified type hints', function (): void {
    $serviceFile = $this->fixtureDir.'/BazService.php';
    file_put_contents($serviceFile, '<?php
namespace App\Services;
class BazService {
    public function __construct(private \App\Repositories\BazRepository $repo) {}
}
');

    $classMap = ['App\\Services\\BazService' => $serviceFile];

    $builder = new DependencyIndexBuilder();
    $index = $builder->build($classMap);

    expect($index)->toHaveKey('App\\Repositories\\BazRepository');
    expect($index['App\\Repositories\\BazRepository'])->toContain('App\\Services\\BazService');
});

test('ignores scalar type hints', function (): void {
    $serviceFile = $this->fixtureDir.'/ScalarService.php';
    file_put_contents($serviceFile, '<?php
namespace App\Services;
class ScalarService {
    public function __construct(private string $name, private int $count) {}
}
');

    $classMap = ['App\\Services\\ScalarService' => $serviceFile];

    $builder = new DependencyIndexBuilder();
    $index = $builder->build($classMap);

    expect($index)->not->toHaveKey('string');
    expect($index)->not->toHaveKey('int');
});

test('deduplicates callers', function (): void {
    $serviceFile = $this->fixtureDir.'/Services.php';
    file_put_contents($serviceFile, '<?php
namespace App\Services;
use App\Repositories\FooRepository;
class ServiceA {
    public function __construct(private FooRepository $repo) {}
}
class ServiceB {
    public function __construct(private FooRepository $repo) {}
}
');

    $classMap = [
        'App\\Services\\ServiceA' => $serviceFile,
        'App\\Services\\ServiceB' => $serviceFile,
    ];

    $builder = new DependencyIndexBuilder();
    $index = $builder->build($classMap);

    expect($index)->toHaveKey('App\\Repositories\\FooRepository');
    expect($index['App\\Repositories\\FooRepository'])->toContain('App\\Services\\ServiceA');
    expect($index['App\\Repositories\\FooRepository'])->toContain('App\\Services\\ServiceB');
});

test('progress callback receives file paths', function (): void {
    $serviceFile = $this->fixtureDir.'/FooService.php';
    file_put_contents($serviceFile, '<?php namespace App; class Foo {}');

    $visited = [];
    $builder = new DependencyIndexBuilder();
    $builder->build(['App\\Foo' => $serviceFile], function (string $path) use (&$visited): void {
        $visited[] = $path;
    });

    expect($visited)->toContain($serviceFile);
});

<?php

namespace EmilienKopp\LaravelDepth\Tests\Unit;

use EmilienKopp\LaravelDepth\Core\DependencyIndexBuilder;
use PHPUnit\Framework\TestCase;

class DependencyIndexBuilderTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/laravel-depth-dep-test-' . uniqid();
        mkdir($this->fixtureDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmdirRecursive($this->fixtureDir);
    }

    public function test_builds_reverse_index_from_constructor_injection(): void
    {
        $serviceFile = $this->fixtureDir . '/FooService.php';
        file_put_contents($serviceFile, '<?php
namespace App\Services;
use App\Repositories\FooRepository;
class FooService {
    public function __construct(private FooRepository $repo) {}
}
');

        $repoFile = $this->fixtureDir . '/FooRepository.php';
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

        $this->assertArrayHasKey('App\\Repositories\\FooRepository', $index);
        $this->assertContains('App\\Services\\FooService', $index['App\\Repositories\\FooRepository']);
    }

    public function test_handles_nullable_typehints(): void
    {
        $serviceFile = $this->fixtureDir . '/FooService.php';
        file_put_contents($serviceFile, '<?php
namespace App\Services;
use App\Repositories\FooRepository;
class FooService {
    public function __construct(private ?FooRepository $repo = null) {}
}
');

        $repoFile = $this->fixtureDir . '/FooRepository.php';
        file_put_contents($repoFile, '<?php namespace App\Repositories; class FooRepository {}');

        $classMap = [
            'App\\Services\\FooService' => $serviceFile,
            'App\\Repositories\\FooRepository' => $repoFile,
        ];

        $builder = new DependencyIndexBuilder();
        $index = $builder->build($classMap);

        $this->assertArrayHasKey('App\\Repositories\\FooRepository', $index);
        $this->assertContains('App\\Services\\FooService', $index['App\\Repositories\\FooRepository']);
    }

    public function test_resolves_fully_qualified_typehints(): void
    {
        $serviceFile = $this->fixtureDir . '/BazService.php';
        file_put_contents($serviceFile, '<?php
namespace App\Services;
class BazService {
    public function __construct(private \App\Repositories\BazRepository $repo) {}
}
');

        $classMap = ['App\\Services\\BazService' => $serviceFile];

        $builder = new DependencyIndexBuilder();
        $index = $builder->build($classMap);

        $this->assertArrayHasKey('App\\Repositories\\BazRepository', $index);
        $this->assertContains('App\\Services\\BazService', $index['App\\Repositories\\BazRepository']);
    }

    public function test_ignores_scalar_typehints(): void
    {
        $serviceFile = $this->fixtureDir . '/ScalarService.php';
        file_put_contents($serviceFile, '<?php
namespace App\Services;
class ScalarService {
    public function __construct(private string $name, private int $count) {}
}
');

        $classMap = ['App\\Services\\ScalarService' => $serviceFile];

        $builder = new DependencyIndexBuilder();
        $index = $builder->build($classMap);

        $this->assertArrayNotHasKey('string', $index);
        $this->assertArrayNotHasKey('int', $index);
    }

    public function test_deduplicates_callers(): void
    {
        // Same file defining two classes that both inject FooRepository would only appear once
        $serviceFile = $this->fixtureDir . '/Services.php';
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

        $this->assertArrayHasKey('App\\Repositories\\FooRepository', $index);
        $callers = $index['App\\Repositories\\FooRepository'];
        $this->assertContains('App\\Services\\ServiceA', $callers);
        $this->assertContains('App\\Services\\ServiceB', $callers);
    }

    public function test_progress_callback_receives_file_paths(): void
    {
        $serviceFile = $this->fixtureDir . '/FooService.php';
        file_put_contents($serviceFile, '<?php namespace App; class Foo {}');

        $visited = [];
        $builder = new DependencyIndexBuilder();
        $builder->build(['App\\Foo' => $serviceFile], function (string $path) use (&$visited) {
            $visited[] = $path;
        });

        $this->assertContains($serviceFile, $visited);
    }

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}

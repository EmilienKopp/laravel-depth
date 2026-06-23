<?php

namespace EmilienKopp\LaravelDepth\Tests\Unit;

use EmilienKopp\LaravelDepth\Core\ClassMapBuilder;
use PHPUnit\Framework\TestCase;

class ClassMapBuilderTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureDir = sys_get_temp_dir() . '/laravel-depth-test-' . uniqid();
        mkdir($this->fixtureDir . '/app', 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rmdirRecursive($this->fixtureDir);
    }

    public function test_builds_class_map_from_simple_class(): void
    {
        file_put_contents($this->fixtureDir . '/app/FooService.php', '<?php
namespace App\Services;
class FooService {}
');

        $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
        $result = $builder->build();

        $this->assertArrayHasKey('App\\Services\\FooService', $result['classMap']);
        $this->assertStringEndsWith('FooService.php', $result['classMap']['App\\Services\\FooService']);
    }

    public function test_marks_interfaces(): void
    {
        file_put_contents($this->fixtureDir . '/app/FooInterface.php', '<?php
namespace App\Contracts;
interface FooInterface {}
');

        $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
        $result = $builder->build();

        $this->assertArrayHasKey('App\\Contracts\\FooInterface', $result['classMap']);
        $this->assertArrayHasKey('App\\Contracts\\FooInterface', $result['interfaces']);
        $this->assertArrayNotHasKey('App\\Contracts\\FooInterface', $result['abstracts']);
    }

    public function test_marks_abstract_classes(): void
    {
        file_put_contents($this->fixtureDir . '/app/AbstractFoo.php', '<?php
namespace App;
abstract class AbstractFoo {}
');

        $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
        $result = $builder->build();

        $this->assertArrayHasKey('App\\AbstractFoo', $result['classMap']);
        $this->assertArrayHasKey('App\\AbstractFoo', $result['abstracts']);
        $this->assertArrayNotHasKey('App\\AbstractFoo', $result['interfaces']);
    }

    public function test_excludes_path_segments(): void
    {
        mkdir($this->fixtureDir . '/app/vendor', 0777, true);
        file_put_contents($this->fixtureDir . '/app/vendor/VendorClass.php', '<?php
namespace Vendor;
class VendorClass {}
');

        $builder = new ClassMapBuilder($this->fixtureDir, ['app'], ['vendor']);
        $result = $builder->build();

        $this->assertArrayNotHasKey('Vendor\\VendorClass', $result['classMap']);
    }

    public function test_skips_non_existent_scan_dirs(): void
    {
        $builder = new ClassMapBuilder($this->fixtureDir, ['nonexistent']);
        $result = $builder->build();

        $this->assertEmpty($result['classMap']);
    }

    public function test_collects_multiple_classes_from_same_file(): void
    {
        file_put_contents($this->fixtureDir . '/app/Multiple.php', '<?php
namespace App\Multi;
class First {}
class Second {}
interface IThird {}
');

        $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
        $result = $builder->build();

        $this->assertArrayHasKey('App\\Multi\\First', $result['classMap']);
        $this->assertArrayHasKey('App\\Multi\\Second', $result['classMap']);
        $this->assertArrayHasKey('App\\Multi\\IThird', $result['classMap']);
        $this->assertArrayHasKey('App\\Multi\\IThird', $result['interfaces']);
    }

    public function test_progress_callback_receives_file_paths(): void
    {
        file_put_contents($this->fixtureDir . '/app/Bar.php', '<?php class Bar {}');

        $visited = [];
        $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
        $builder->build(function (string $path) use (&$visited) {
            $visited[] = $path;
        });

        $this->assertNotEmpty($visited);
        $this->assertStringEndsWith('.php', $visited[0]);
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

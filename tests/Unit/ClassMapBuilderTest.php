<?php

declare(strict_types=1);

use EmilienKopp\LaravelDepth\Core\ClassMapBuilder;

beforeEach(function (): void {
    $this->fixtureDir = sys_get_temp_dir().'/laravel-depth-test-'.uniqid();
    mkdir($this->fixtureDir.'/app', 0777, true);
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

test('builds class map from simple class', function (): void {
    file_put_contents($this->fixtureDir.'/app/FooService.php', '<?php
namespace App\Services;
class FooService {}
');

    $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
    $result = $builder->build();

    expect($result['classMap'])->toHaveKey('App\\Services\\FooService');
    expect($result['classMap']['App\\Services\\FooService'])->toEndWith('FooService.php');
});

test('marks interfaces', function (): void {
    file_put_contents($this->fixtureDir.'/app/FooInterface.php', '<?php
namespace App\Contracts;
interface FooInterface {}
');

    $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
    $result = $builder->build();

    expect($result['classMap'])->toHaveKey('App\\Contracts\\FooInterface');
    expect($result['interfaces'])->toHaveKey('App\\Contracts\\FooInterface');
    expect($result['abstracts'])->not->toHaveKey('App\\Contracts\\FooInterface');
});

test('marks abstract classes', function (): void {
    file_put_contents($this->fixtureDir.'/app/AbstractFoo.php', '<?php
namespace App;
abstract class AbstractFoo {}
');

    $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
    $result = $builder->build();

    expect($result['classMap'])->toHaveKey('App\\AbstractFoo');
    expect($result['abstracts'])->toHaveKey('App\\AbstractFoo');
    expect($result['interfaces'])->not->toHaveKey('App\\AbstractFoo');
});

test('excludes path segments', function (): void {
    mkdir($this->fixtureDir.'/app/vendor', 0777, true);
    file_put_contents($this->fixtureDir.'/app/vendor/VendorClass.php', '<?php
namespace Vendor;
class VendorClass {}
');

    $builder = new ClassMapBuilder($this->fixtureDir, ['app'], ['vendor']);
    $result = $builder->build();

    expect($result['classMap'])->not->toHaveKey('Vendor\\VendorClass');
});

test('skips non existent scan dirs', function (): void {
    $builder = new ClassMapBuilder($this->fixtureDir, ['nonexistent']);
    $result = $builder->build();

    expect($result['classMap'])->toBeEmpty();
});

test('collects multiple classes from same file', function (): void {
    file_put_contents($this->fixtureDir.'/app/Multiple.php', '<?php
namespace App\Multi;
class First {}
class Second {}
interface IThird {}
');

    $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
    $result = $builder->build();

    expect($result['classMap'])->toHaveKey('App\\Multi\\First');
    expect($result['classMap'])->toHaveKey('App\\Multi\\Second');
    expect($result['classMap'])->toHaveKey('App\\Multi\\IThird');
    expect($result['interfaces'])->toHaveKey('App\\Multi\\IThird');
});

test('progress callback receives file paths', function (): void {
    file_put_contents($this->fixtureDir.'/app/Bar.php', '<?php class Bar {}');

    $visited = [];
    $builder = new ClassMapBuilder($this->fixtureDir, ['app']);
    $builder->build(function (string $path) use (&$visited): void {
        $visited[] = $path;
    });

    expect($visited)->not->toBeEmpty();
    expect($visited[0])->toEndWith('.php');
});

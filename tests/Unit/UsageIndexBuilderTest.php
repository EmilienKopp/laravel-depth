<?php

declare(strict_types=1);

use EmilienKopp\LaravelDepth\Core\UsageIndexBuilder;

beforeEach(function (): void {
    $this->fixtureDir = sys_get_temp_dir().'/laravel-depth-usage-test-'.uniqid();
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

test('collects traits extends implements properties and method params', function (): void {
    $filePath = $this->fixtureDir.'/Service.php';
    file_put_contents($filePath, '<?php
namespace App\\Services;

use App\\Contracts\\SomeContract;
use App\\Dtos\\InputDto;
use App\\Dtos\\OutputDto;
use App\\Models\\BaseService;
use App\\Traits\\Auditable;

class ExampleQueryService extends BaseService implements SomeContract
{
    use Auditable;

    private ?InputDto $dto = null;

    public function handle(OutputDto|InputDto $payload): void
    {
    }
}
');

    $classMap = [
        'App\\Services\\ExampleQueryService' => $filePath,
    ];

    $builder = new UsageIndexBuilder();
    $usageIndex = $builder->build($classMap);

    expect($usageIndex)->toHaveKey('App\\Services\\ExampleQueryService');
    $symbols = $usageIndex['App\\Services\\ExampleQueryService'];

    expect($symbols)->toHaveKey('App\\Models\\BaseService');
    expect($symbols)->toHaveKey('App\\Contracts\\SomeContract');
    expect($symbols)->toHaveKey('App\\Traits\\Auditable');
    expect($symbols)->toHaveKey('App\\Dtos\\InputDto');
    expect($symbols)->toHaveKey('App\\Dtos\\OutputDto');
});

test('progress callback receives file paths', function (): void {
    $filePath = $this->fixtureDir.'/Simple.php';
    file_put_contents($filePath, '<?php namespace App; class Simple {}');

    $visited = [];
    $builder = new UsageIndexBuilder();
    $builder->build(['App\\Simple' => $filePath], function (string $path) use (&$visited): void {
        $visited[] = $path;
    });

    expect($visited)->toContain($filePath);
});

<?php

namespace EmilienKopp\LaravelDepth\Core;

use EmilienKopp\LaravelDepth\Core\Visitors\ClassMapVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scans configurable directories for PHP files and builds a class map
 * (FQCN => file path) using nikic/php-parser AST parsing.
 * Also tracks which FQCNs are interfaces and abstract classes.
 */
class ClassMapBuilder
{
    public function __construct(
        private readonly string $basePath,
        private readonly array $scanDirectories,
        private readonly array $excludedPaths = [],
    ) {
    }

    /**
     * Build the class map by scanning all configured directories.
     *
     * @param  callable|null  $progress  Invoked with the file path being scanned
     * @return array{
     *   classMap: array<string, string>,
     *   interfaces: array<string, true>,
     *   abstracts: array<string, true>
     * }
     */
    public function build(callable $progress = null): array
    {
        $classMap = [];
        $interfaces = [];
        $abstracts = [];

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        foreach ($this->scanDirectories as $dir) {
            $fullPath = rtrim($this->basePath, '/') . '/' . ltrim((string) $dir, '/');
            if (! is_dir($fullPath)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $filePath = $file->getRealPath();
                if ($filePath === false || $this->isExcluded($filePath)) {
                    continue;
                }

                if ($progress !== null) {
                    ($progress)($filePath);
                }

                try {
                    $code = file_get_contents($filePath);
                    if ($code === false) {
                        continue;
                    }

                    $ast = $parser->parse($code);
                    if ($ast === null) {
                        continue;
                    }

                    $visitor = new ClassMapVisitor($filePath);
                    $traverser = new NodeTraverser();
                    $traverser->addVisitor($visitor);
                    $traverser->traverse($ast);

                    foreach ($visitor->getClassMap() as $fqcn => $path) {
                        $classMap[$fqcn] = $path;
                    }
                    foreach ($visitor->getInterfaces() as $fqcn => $_) {
                        $interfaces[$fqcn] = true;
                    }
                    foreach ($visitor->getAbstracts() as $fqcn => $_) {
                        $abstracts[$fqcn] = true;
                    }
                } catch (\Throwable) {
                    // Skip files that cannot be parsed
                }
            }
        }

        return compact('classMap', 'interfaces', 'abstracts');
    }

    private function isExcluded(string $filePath): bool
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        foreach ($this->excludedPaths as $excluded) {
            $segment = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $excluded);
            if (
                str_contains($normalized, DIRECTORY_SEPARATOR . $segment . DIRECTORY_SEPARATOR)
                || str_ends_with($normalized, DIRECTORY_SEPARATOR . $segment)
            ) {
                return true;
            }
        }

        return false;
    }
}

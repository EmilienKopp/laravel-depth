<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Core\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visits PHP AST nodes to build a map of FQCN => file path.
 * Also tracks which FQCNs are interfaces and which are abstract classes.
 */
final class ClassMapVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    private array $classMap = [];

    private array $interfaces = [];

    private array $abstracts = [];

    public function __construct(private readonly string $filePath) {}

    public function enterNode(Node $node): int|Node|null
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name instanceof Node\Name ? $node->name->toString() : '';

            return null;
        }

        if ($node instanceof Node\Stmt\Class_ && $node->name instanceof Node\Identifier) {
            $fqcn = $this->buildFqcn($node->name->toString());
            $this->classMap[$fqcn] = $this->filePath;
            if ($node->isAbstract()) {
                $this->abstracts[$fqcn] = true;
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Interface_) {
            $fqcn = $this->buildFqcn($node->name->toString());
            $this->classMap[$fqcn] = $this->filePath;
            $this->interfaces[$fqcn] = true;

            return null;
        }

        if ($node instanceof Node\Stmt\Trait_) {
            $fqcn = $this->buildFqcn($node->name->toString());
            $this->classMap[$fqcn] = $this->filePath;

            return null;
        }

        return null;
    }

    /** @return array<string, string> FQCN => file path */
    public function getClassMap(): array
    {
        return $this->classMap;
    }

    /** @return array<string, true> Set of interface FQCNs */
    public function getInterfaces(): array
    {
        return $this->interfaces;
    }

    /** @return array<string, true> Set of abstract class FQCNs */
    public function getAbstracts(): array
    {
        return $this->abstracts;
    }

    private function buildFqcn(string $name): string
    {
        return $this->namespace !== '' ? $this->namespace.'\\'.$name : $name;
    }
}

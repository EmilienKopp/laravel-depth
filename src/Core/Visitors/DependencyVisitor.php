<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Core\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visits PHP AST nodes to extract constructor injection typehints.
 * Resolves typehints to FQCNs using the file's namespace and use statements.
 */
final class DependencyVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    /** @var array<string, string> alias => FQCN */
    private array $uses = [];

    /** @var list<string|null> Stack of current class FQCNs (null = anonymous) */
    private array $classStack = [];

    /** @var array<string, list<string>> caller FQCN => [injected FQCNs] */
    private array $dependencies = [];

    public function enterNode(Node $node): int|Node|null
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->namespace = $node->name instanceof Node\Name ? $node->name->toString() : '';
            $this->uses = [];

            return null;
        }

        if ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
            foreach ($node->uses as $use) {
                $alias = $use->alias !== null ? $use->alias->toString() : $use->name->getLast();
                $this->uses[$alias] = $use->name->toString();
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Class_) {
            $this->classStack[] = $node->name instanceof Node\Identifier ? $this->buildFqcn($node->name->toString()) : null;

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === '__construct') {
            $currentClass = $this->classStack === [] ? null : end($this->classStack);
            if ($currentClass === null) {
                return null;
            }

            foreach ($node->params as $param) {
                $resolved = $this->resolveType($param->type);
                if ($resolved !== null) {
                    $this->dependencies[$currentClass][] = $resolved;
                }
            }

            return null;
        }

        return null;
    }

    public function leaveNode(Node $node): int|Node|array|null
    {
        if ($node instanceof Node\Stmt\Class_) {
            array_pop($this->classStack);
        }

        return null;
    }

    /** @return array<string, list<string>> caller FQCN => [injected FQCNs] */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    private function resolveType(?Node $type): ?string
    {
        if (! $type instanceof Node) {
            return null;
        }

        if ($type instanceof Node\NullableType) {
            return $this->resolveType($type->type);
        }

        if ($type instanceof Node\Name\FullyQualified) {
            return $type->toString();
        }

        if ($type instanceof Node\Name) {
            return $this->resolveName($type);
        }

        // Node\Identifier (int, string, bool, etc.), union, intersection — skip
        return null;
    }

    private function resolveName(Node\Name $name): string
    {
        $parts = $name->getParts();
        $first = $parts[0];

        if (isset($this->uses[$first])) {
            $resolved = $this->uses[$first];
            if (count($parts) > 1) {
                $resolved .= '\\'.implode('\\', array_slice($parts, 1));
            }

            return $resolved;
        }

        $nameStr = $name->toString();

        return $this->namespace !== '' ? $this->namespace.'\\'.$nameStr : $nameStr;
    }

    private function buildFqcn(string $name): string
    {
        return $this->namespace !== '' ? $this->namespace.'\\'.$name : $name;
    }
}

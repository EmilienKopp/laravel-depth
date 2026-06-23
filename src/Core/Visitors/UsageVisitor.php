<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Core\Visitors;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Visits PHP AST nodes to collect per-class symbol usage metadata.
 *
 * Captures trait usage, extends/implements, property types,
 * and class method parameter types.
 */
final class UsageVisitor extends NodeVisitorAbstract
{
    private string $namespace = '';

    /** @var array<string, string> alias => FQCN */
    private array $uses = [];

    /** @var list<string|null> Stack of current class FQCNs (null = anonymous) */
    private array $classStack = [];

    /** @var array<string, array<string, true>> */
    private array $usageIndex = [];

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
            if (! $node->name instanceof Node\Identifier) {
                $this->classStack[] = null;

                return null;
            }

            $fqcn = $this->buildFqcn($node->name->toString());
            $this->classStack[] = $fqcn;
            $this->usageIndex[$fqcn] ??= [];

            if ($node->extends instanceof Node\Name) {
                $this->addResolvedName($fqcn, $node->extends);
            }

            foreach ($node->implements as $implemented) {
                $this->addResolvedName($fqcn, $implemented);
            }

            return null;
        }

        if ($node instanceof Node\Stmt\TraitUse) {
            $currentClass = $this->currentClass();
            if ($currentClass === null) {
                return null;
            }

            foreach ($node->traits as $traitName) {
                $this->addResolvedName($currentClass, $traitName);
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            $currentClass = $this->currentClass();
            if ($currentClass === null || ! $node->type instanceof Node) {
                return null;
            }

            foreach ($this->resolveTypeNames($node->type) as $resolvedType) {
                $this->usageIndex[$currentClass][$resolvedType] = true;
            }

            return null;
        }

        if ($node instanceof Node\Stmt\ClassMethod) {
            $currentClass = $this->currentClass();
            if ($currentClass === null) {
                return null;
            }

            foreach ($node->params as $param) {
                foreach ($this->resolveTypeNames($param->type) as $resolvedType) {
                    $this->usageIndex[$currentClass][$resolvedType] = true;
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

    /** @return array<string, array<string, true>> */
    public function getUsageIndex(): array
    {
        return $this->usageIndex;
    }

    private function currentClass(): ?string
    {
        return $this->classStack === [] ? null : end($this->classStack);
    }

    private function addResolvedName(string $classFqcn, Node\Name $name): void
    {
        $resolved = $this->resolveName($name);
        $this->usageIndex[$classFqcn][$resolved] = true;
    }

    /** @return list<string> */
    private function resolveTypeNames(Node $type): array
    {
        if ($type instanceof Node\NullableType) {
            return $this->resolveTypeNames($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $resolved = [];
            foreach ($type->types as $innerType) {
                foreach ($this->resolveTypeNames($innerType) as $name) {
                    $resolved[] = $name;
                }
            }

            return array_values(array_unique($resolved));
        }

        if ($type instanceof Node\Name\FullyQualified) {
            return [$type->toString()];
        }

        if ($type instanceof Node\Name) {
            return [$this->resolveName($type)];
        }

        return [];
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

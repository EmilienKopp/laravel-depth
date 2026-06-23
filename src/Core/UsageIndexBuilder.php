<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Core;

use EmilienKopp\LaravelDepth\Core\Visitors\UsageVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Builds a per-class symbol usage index from AST analysis.
 *
 * Usage currently includes:
 * - used traits
 * - extends/implements
 * - typed properties
 * - class method parameter types
 */
final class UsageIndexBuilder
{
    /**
     * @param  array<string, string>  $classMap  FQCN => file path
     * @param  callable|null  $progress  Invoked with each file path being processed
     * @return array<string, array<string, true>> class FQCN => set of used symbol FQCNs
     */
    public function build(array $classMap, ?callable $progress = null): array
    {
        $usageIndex = [];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $uniqueFiles = array_unique(array_values($classMap));

        foreach ($uniqueFiles as $filePath) {
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

                $visitor = new UsageVisitor();
                $traverser = new NodeTraverser();
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);

                foreach ($visitor->getUsageIndex() as $fqcn => $symbols) {
                    $usageIndex[$fqcn] ??= [];
                    foreach ($symbols as $symbol => $_) {
                        $usageIndex[$fqcn][$symbol] = true;
                    }
                }
            } catch (Throwable) {
                // Skip files that cannot be parsed
            }
        }

        return $usageIndex;
    }
}

<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Core;

use EmilienKopp\LaravelDepth\Core\Support\Utils;
use EmilienKopp\LaravelDepth\Core\Visitors\DependencyVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Builds a reverse dependency index by parsing constructor injection typehints.
 *
 * The index maps: injected FQCN => list of FQCNs that inject it.
 * Each file is parsed only once even if multiple classes reside in it.
 */
final class DependencyIndexBuilder
{
    /**
     * Build the reverse dependency index.
     *
     * @param  array<string, string>  $classMap  FQCN => file path
     * @param  callable|null  $progress  Invoked with the file path being processed
     * @return array<string, list<string>> injectedFQCN => [callerFQCN, ...]
     */
    public function build(array $classMap, ?callable $progress = null): array
    {
        $reverseIndex = [];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        // Deduplicate: parse each unique file only once
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

                $visitor = new DependencyVisitor();
                $traverser = new NodeTraverser();
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);

                $reverseIndex = Utils::reverseIndex($visitor->getDependencies());
            } catch (Throwable) {
                // Skip files that cannot be parsed
            }
        }

        // Deduplicate callers per injected class
        foreach ($reverseIndex as &$callers) {
            $callers = array_values(array_unique($callers));
        }

        unset($callers);

        return $reverseIndex;
    }
}

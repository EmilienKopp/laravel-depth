<?php

declare(strict_types=1);

namespace EmilienKopp\LaravelDepth\Core\Support;

final class Utils
{
    public static function reverseIndex(array $index): array
    {
        $reverseIndex = [];

        foreach ($index as $caller => $callees) {
            foreach ($callees as $callee) {
                $reverseIndex[$callee][] = $caller;
            }
        }

        return $reverseIndex;
    }
}

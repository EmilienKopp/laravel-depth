<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Scan Directories
    |--------------------------------------------------------------------------
    |
    | Directories to scan for PHP class files, relative to base_path().
    |
    */
    'scan_directories' => [
        'Modules',
        'app',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    |
    | Path segments to exclude from scanning (e.g. vendor, test directories).
    |
    */
    'excluded_paths' => [
        'vendor',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entry Point Suffixes
    |--------------------------------------------------------------------------
    |
    | Class name suffixes that mark a class as an entry point in the
    | dependency tree. Tracing stops when one of these is reached.
    |
    */
    'entry_point_suffixes' => [
        'Controller',
        'Job',
        'Command',
        'Listener',
        'Webhook',
    ],

    /*
    |--------------------------------------------------------------------------
    | Verbose Output
    |--------------------------------------------------------------------------
    |
    | Whether to show progress messages during scanning and tracing.
    |
    */
    'verbose' => false,
];

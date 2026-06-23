<?php

namespace EmilienKopp\LaravelDepth;

use EmilienKopp\LaravelDepth\Commands\TraceCommand;
use Illuminate\Support\ServiceProvider;

class DepthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/depth.php',
            'depth'
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/depth.php' => config_path('depth.php'),
            ], 'depth-config');

            $this->commands([
                TraceCommand::class,
            ]);
        }
    }
}

<?php

namespace Hansonxyz\HeadlessBrowserTester;

use Illuminate\Support\ServiceProvider;

class HeadlessBrowserTesterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/headless-browser-tester.php', 'headless-browser-tester');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\TestRouteCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/headless-browser-tester.php' => config_path('headless-browser-tester.php'),
            ], 'config');
        }
    }
}

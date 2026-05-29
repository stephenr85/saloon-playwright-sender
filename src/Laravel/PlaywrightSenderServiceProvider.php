<?php

declare(strict_types=1);

namespace Rushing\SaloonPlaywright\Laravel;

use Illuminate\Support\ServiceProvider;
use Rushing\SaloonPlaywright\PlaywrightServiceConfig;

class PlaywrightSenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/playwright-sender.php', 'playwright-sender');

        $this->app->singleton(PlaywrightServiceConfig::class, function () {
            return new PlaywrightServiceConfig(
                serviceUrl: config('playwright-sender.service_url'),
                timeout: (int) config('playwright-sender.timeout'),
                responseMode: config('playwright-sender.response_mode'),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/playwright-sender.php' => config_path('playwright-sender.php'),
            ], 'playwright-sender-config');
        }
    }
}

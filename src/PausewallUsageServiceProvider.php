<?php

namespace Vipertecpro\PausewallUsage;

use Illuminate\Support\ServiceProvider;

class PausewallUsageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PausewallUsage::class, fn () => new PausewallUsage);
    }
}

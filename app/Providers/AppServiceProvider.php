<?php

namespace App\Providers;

use App\Services\CategoryTreeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CategoryTreeService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('kaspi-import', function (Request $request) {
            return Limit::perMinute((int) config('services.kaspi.production_import_rate_limit', 30))
                ->by($request->ip() ?: 'kaspi-import');
        });
    }
}

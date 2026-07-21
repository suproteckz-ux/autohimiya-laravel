<?php

namespace App\Providers;

use App\Services\CategoryTreeService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CategoryTreeService::class);
    }

    public function boot(): void
    {
        //
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\ArtisanRunner;
use App\Console\ProcessArtisanRunner;
use Illuminate\Support\ServiceProvider;

/**
 * Registers and boots application-wide services.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ArtisanRunner::class, ProcessArtisanRunner::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

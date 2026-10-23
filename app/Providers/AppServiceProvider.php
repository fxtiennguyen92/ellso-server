<?php

namespace App\Providers;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Alpine component nạp bất đồng bộ cho CMS (build bằng: node bin/build.js, rồi php artisan filament:assets)
        FilamentAsset::register([
            AlpineComponent::make('lesson-editor', __DIR__ . '/../../resources/js/dist/components/lesson-editor.js'),
        ]);
    }
}
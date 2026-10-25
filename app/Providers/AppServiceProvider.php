<?php

namespace App\Providers;

use Filament\Support\Assets\AlpineComponent;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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

        $this->configureRateLimiting();
    }

    /** Giới hạn tần suất cho các API công khai (chống spam / dò email). */
    private function configureRateLimiting(): void
    {
        $tooMany = fn (Request $request, array $headers) => response()->json([
            'message' => 'Bạn thao tác quá nhanh. Vui lòng thử lại sau ít phút.',
            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(config('ellso.auth.register_max_per_ip_per_hour'))
            ->by('register:' . $request->ip())
            ->response($tooMany));

        RateLimiter::for('forgot-password', fn (Request $request) => [
            Limit::perHour(config('ellso.auth.forgot_password.max_per_ip_per_hour'))
                ->by('forgot-ip:' . $request->ip())
                ->response($tooMany),
            // Chặn dò hàng loạt cùng 1 email từ nhiều IP (bổ sung cho cooldown theo user trong controller)
            Limit::perDay(config('ellso.auth.forgot_password.max_per_email_per_day') * 2)
                ->by('forgot-email:' . Str::lower(trim((string) $request->input('email'))))
                ->response($tooMany),
        ]);

        // Theo người dùng: chống dò mã khuyến mãi
        RateLimiter::for('promo-code-check', fn (Request $request) => Limit::perMinute(10)
            ->by('promo:' . ($request->user()?->getKey() ?? $request->ip()))
            ->response($tooMany));

        RateLimiter::for('referral-check', fn (Request $request) => Limit::perMinute(config('ellso.auth.referral_check_max_per_ip_per_minute'))
            ->by('referral:' . $request->ip())
            ->response($tooMany));
    }
}
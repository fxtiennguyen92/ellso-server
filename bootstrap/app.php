<?php

use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\UseRequestHostForPublicFiles;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        // routes/api.php chỉ đăng ký 1 lần ở `then` bên dưới (domain API + prefix v1), không có /api/...
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->domain(config('app.api_domain'))
                ->prefix('v1') // https://api.ellso.edu.vn/v1/...
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Link ảnh /storage theo domain đang gọi (CMS hay API), tránh lỗi khác origin
        $middleware->prepend(UseRequestHostForPublicFiles::class);

        // Production sau CloudFront: IP thật (TRUSTED_PROXIES) + https (FORCE_HTTPS). Local không bật gì.
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);
        $middleware->prepend(ForceHttps::class);

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API luôn trả JSON (kể cả khi app quên header Accept: application/json)
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson() || $request->is('api/*', 'v1/*'),
        );

        $exceptions->render(fn (MissingAbilityException $e, Request $request) => response()->json(
            ['message' => 'Không có quyền thực hiện thao tác này.'],
            403,
        ));
    })->create();

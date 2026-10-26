<?php

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public const NAV_GROUPS = ['Tài khoản', 'Đào tạo', 'Thiết lập', 'Marketing', 'Thống kê', 'Hệ thống'];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domain(config('app.cms_domain'))
            ->login()
            // Logo Ellso thay chữ ở trang đăng nhập và góc trên bên trái (SVG, nét ở mọi kích thước)
            ->brandName('Ellso')
            ->brandLogo(fn () => asset('images/ellso-logo.svg'))
            ->brandLogoHeight('2rem')
            // Favicon: nền đen, chữ "e" trắng (SVG; kèm favicon.ico + apple-touch-icon cho trình duyệt cũ / iOS)
            ->favicon(fn () => asset('images/favicon.svg'))
            ->colors([
                'primary' => Color::Emerald, // đồng bộ màu thương hiệu Ellso (#10B981)
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            // Avatar chữ cái đầu tạo tại chỗ, không gọi ui-avatars.com
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Báo "Đăng nhập thành công, đang mở trang quản trị…" trong lúc chuyển trang
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn () => view('filament.auth.login-redirect-overlay'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Nhóm menu mặc định đóng; nhóm chứa trang đang xem luôn mở (xem view filament.navigation-groups)
            ->navigationGroups(collect(self::NAV_GROUPS)->map(fn (string $label) => NavigationGroup::make($label)->collapsed())->all())
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.navigation-groups', ['groups' => self::NAV_GROUPS]))
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => new \Illuminate\Support\HtmlString(
                '<link rel="alternate icon" href="' . asset('favicon.ico') . '" sizes="48x48">'
                . '<link rel="apple-touch-icon" href="' . asset('images/apple-touch-icon.png') . '">'
            ));
    }
}

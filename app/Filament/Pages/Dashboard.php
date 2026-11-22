<?php

namespace App\Filament\Pages;

use App\Filament\Statistics\Widgets\StudentActivityOverview;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\AccountWidget;

/**
 * Trang đầu tiên sau khi đăng nhập.
 * Admin: số liệu học viên toàn thời gian (giống đầu trang Thống kê › Hoạt động học viên).
 * Giảng viên: thẻ tài khoản như trước.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Tổng quan';

    public function getWidgets(): array
    {
        return auth()->user()?->isAdmin()
            ? [StudentActivityOverview::class]
            : [AccountWidget::class];
    }

    public function getColumns(): int | array
    {
        return 1;
    }
}

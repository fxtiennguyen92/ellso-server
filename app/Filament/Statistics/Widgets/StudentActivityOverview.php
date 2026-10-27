<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\StudentActivityStatistics;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** 3 thẻ toàn thời gian / hiện tại — không phụ thuộc bộ lọc ngày. */
class StudentActivityOverview extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Toàn thời gian';

    protected function getColumns(): int | array | null
    {
        return ['default' => 1, 'md' => 3];
    }

    protected function getStats(): array
    {
        $o = StudentActivityStatistics::overview();
        $pct = fn (float $value) => rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',') . '%';

        $shares = collect($o['platforms'])
            ->filter()
            ->map(fn (int $count, string $key) => [
                'label' => StudentActivityStatistics::platformLabel($key),
                'pct' => $o['opens'] ? $count / $o['opens'] * 100 : 0,
            ]);
        $top = $shares->sortByDesc('pct')->first();

        return [
            Stat::make('Tổng học viên', number_format($o['students'], 0, ',', '.'))
                ->description('Tài khoản vai trò học viên')
                ->icon(Heroicon::OutlinedUsers),
            Stat::make('Tỉ lệ có gói học viên', $pct($o['membership_rate']))
                ->description(number_format($o['active_members'], 0, ',', '.') . ' đang còn hạn · '
                    . number_format($o['ever_members'], 0, ',', '.') . ' từng mua')
                ->icon(Heroicon::OutlinedIdentification),
            Stat::make('Nền tảng', $top ? $top['label'] . ' ' . $pct($top['pct']) : '—')
                ->description($shares->isEmpty()
                    ? 'Chưa có lượt mở app'
                    : $shares->map(fn ($s) => $s['label'] . ' ' . $pct($s['pct']))->join(' · '))
                ->icon(Heroicon::OutlinedDevicePhoneMobile),
        ];
    }
}

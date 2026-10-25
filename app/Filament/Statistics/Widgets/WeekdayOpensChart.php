<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\TimeBuckets;
use Filament\Support\RawJs;

/** Lượt mở app theo thứ trong tuần (T2 → CN), trong khoảng đã lọc. */
class WeekdayOpensChart extends StudentActivityChart
{
    protected ?string $maxHeight = '240px';

    public function getHeading(): string
    {
        return 'Theo ngày trong tuần';
    }

    public function getDescription(): string
    {
        return 'Tổng lượt mở app mỗi thứ';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return [
            'labels' => array_values(TimeBuckets::weekdayLabels()),
            'datasets' => [$this->bar('Lượt mở app', $this->stats()->activity()['weekdays'])],
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->barOptions("['Thứ Hai','Thứ Ba','Thứ Tư','Thứ Năm','Thứ Sáu','Thứ Bảy','Chủ nhật'][items[0].dataIndex]");
    }
}

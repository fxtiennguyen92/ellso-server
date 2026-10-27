<?php

namespace App\Filament\Statistics\Widgets;

use Filament\Support\RawJs;

/** Lượt mở app theo giờ trong ngày (0h → 23h, giờ Việt Nam), trong khoảng đã lọc. */
class HourlyOpensChart extends StudentActivityChart
{
    protected ?string $maxHeight = '240px';

    public function getHeading(): string
    {
        return 'Theo giờ trong ngày';
    }

    public function getDescription(): string
    {
        return 'Giờ Việt Nam';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        return [
            'labels' => array_map(fn (int $h) => $h . 'h', range(0, 23)),
            'datasets' => [$this->bar('Lượt mở app', $this->stats()->activity()['hours'])],
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->barOptions("items[0].dataIndex + ':00 – ' + items[0].dataIndex + ':59'");
    }
}

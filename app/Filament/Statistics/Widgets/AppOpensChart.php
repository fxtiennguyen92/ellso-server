<?php

namespace App\Filament\Statistics\Widgets;

use Filament\Support\RawJs;

/** Biểu đồ lớn: lượt mở app theo ngày / tuần / tháng. */
class AppOpensChart extends StudentActivityChart
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return 'Lượt mở app';
    }

    public function getDescription(): string
    {
        $a = $this->stats()->activity();
        $n = fn ($v) => number_format($v, 0, ',', '.');

        return $this->stats()->time()->label()
            . ' · ' . $n($a['total']) . ' lượt từ ' . $n($a['students']) . ' học viên'
            . ($a['total'] ? ' · trung bình ' . number_format($a['avg_minutes'], 1, ',', '.') . ' phút/lượt' : '');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $a = $this->stats()->activity();

        return [
            'labels' => $a['labels'],
            'datasets' => [$this->bar('Lượt mở app', $a['opens'])],
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->barOptions();
    }
}

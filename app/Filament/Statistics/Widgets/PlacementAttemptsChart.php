<?php

namespace App\Filament\Statistics\Widgets;

use App\Support\PlacementStatistics;
use Filament\Support\RawJs;

/** Lượt làm bài kiểm tra đầu vào theo ngày / tuần / tháng. */
class PlacementAttemptsChart extends StudentActivityChart
{
    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    private function data(): PlacementStatistics
    {
        return new PlacementStatistics($this->pageFilters);
    }

    public function getHeading(): string
    {
        return 'Lượt làm bài';
    }

    public function getDescription(): string
    {
        return $this->data()->time()->label();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $t = $this->data()->trend();

        return [
            'labels' => $t['labels'],
            'datasets' => [$this->bar('Lượt làm bài', $t['attempts'])],
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->barOptions(unit: 'lượt làm bài');
    }
}

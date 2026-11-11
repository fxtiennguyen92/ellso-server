<?php

namespace App\Filament\Resources\Lessons\Pages;

use App\Filament\Resources\Lessons\LessonResource;
use App\Models\Lesson;
use App\Support\LessonStats;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/** Thống kê trải nghiệm học của một bài (dữ liệu app gửi lên: xem LessonTrackingController). */
class LessonStatsPage extends Page
{
    use InteractsWithRecord;

    protected static string $resource = LessonResource::class;

    protected string $view = 'filament.lessons.stats';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->user()->can('view', $this->getRecord()), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Thống kê: '.$this->getRecord()->title;
    }

    public function getBreadcrumb(): ?string
    {
        return 'Thống kê';
    }

    public function stats(): array
    {
        /** @var Lesson $lesson */
        $lesson = $this->getRecord();

        return LessonStats::for($lesson);
    }
}

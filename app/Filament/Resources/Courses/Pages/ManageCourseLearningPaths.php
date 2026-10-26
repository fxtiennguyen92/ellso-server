<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Enums\CourseType;
use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Resources\LearningPaths\Tables\LearningPathsTable;
use App\Models\Course;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

/** Khóa học › Lộ trình học: danh sách lộ trình của riêng một khóa. */
class ManageCourseLearningPaths extends ManageRelatedRecords
{
    protected static string $resource = CourseResource::class;

    protected static string $relationship = 'learningPaths';

    protected static ?string $relatedResource = LearningPathResource::class;

    protected static ?string $title = 'Lộ trình học';

    protected static ?string $breadcrumb = 'Lộ trình học';

    public function getSubheading(): string | Htmlable | null
    {
        return 'Khóa học: ' . $this->getOwnerRecord()->title;
    }

    public function table(Table $table): Table
    {
        return LearningPathsTable::configure($table)
            ->headerActions([
                // Mở trang tạo lộ trình (resource lồng)
                CreateAction::make()
                    ->label('Thêm lộ trình')
                    ->icon(Heroicon::Plus)
                    ->color('primary')
                    ->visible(fn () => $this->course()->type === CourseType::MultiPath),
            ]);
    }

    private function course(): Course
    {
        /** @var Course */
        return $this->getOwnerRecord();
    }
}
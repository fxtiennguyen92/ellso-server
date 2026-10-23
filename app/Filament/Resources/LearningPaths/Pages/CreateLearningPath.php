<?php

namespace App\Filament\Resources\LearningPaths\Pages;

use App\Enums\CourseType;
use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Filament\Resources\LearningPaths\Schemas\LearningPathForm;
use App\Models\Course;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/** Tạo lộ trình cho khóa cha (resource lồng: /courses/{course}/learning-paths/create). */
class CreateLearningPath extends CreateRecord
{
    protected static string $resource = LearningPathResource::class;

    /** @var array<int> */
    protected array $conditionOptionIds = [];

    public function getSubheading(): ?string
    {
        return 'Khóa học: ' . $this->course()->title;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $course = $this->course();

        // Chặn giảng viên tạo lộ trình cho khóa của người khác
        abort_unless(auth()->user()->can('update', $course), 403);

        if ($course->type === CourseType::Single && $course->learningPaths()->exists()) {
            $this->stop('Khóa học chung chỉ có 1 lộ trình', 'Đổi khóa sang "Đa lộ trình" để thêm lộ trình.');
        }

        $this->conditionOptionIds = LearningPathForm::optionIdsFromState($course->id, $data['conditions'] ?? []);
        unset($data['conditions']);

        if ($duplicate = LearningPathForm::findDuplicatePath($course->id, $this->conditionOptionIds)) {
            $this->stop('Trùng điều kiện', "Lộ trình \"{$duplicate->name}\" đã áp dụng cho đúng các giá trị này.");
        }

        $data['course_id'] = $course->id;

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->getRecord()->options()->sync($this->conditionOptionIds);
    }

    /** Tạo xong → sang trang Bài học của lộ trình. */
    protected function getRedirectUrl(): string
    {
        return LearningPathResource::urlFor('build', $this->getRecord());
    }

    private function course(): Course
    {
        /** @var Course */
        return $this->getParentRecord();
    }

    private function stop(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
        $this->halt();
    }
}

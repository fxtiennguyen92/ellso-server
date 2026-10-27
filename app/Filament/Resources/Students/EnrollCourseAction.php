<?php

namespace App\Filament\Resources\Students;

use App\Enums\CourseType;
use App\Enums\EnrollmentSource;
use App\Models\Course;
use App\Models\LearningPath;
use App\Services\LearningProgress;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/** "Mở khoá học" cho học viên (Học bạ): chọn khoá, tuỳ chọn gán lộ trình, ghi chú. Không thời hạn. */
class EnrollCourseAction
{
    public static function make(callable $user): Action
    {
        return Action::make('enroll')
            ->label('Mở khoá học')
            ->icon(Heroicon::LockOpen)
            ->color('primary')
            ->modalHeading(fn () => 'Mở khoá học cho ' . $user()->name)
            ->modalDescription('Học viên thấy khoá trong "Khoá học của tôi" trên app. Không thời hạn.')
            ->modalWidth('lg')
            ->schema([
                Select::make('course_id')
                    ->label('Khoá học')
                    ->options(fn () => Course::query()
                        ->whereNotIn('id', $user()->enrollments()->pluck('course_id'))
                        ->orderBy('title')
                        ->get()
                        ->mapWithKeys(fn (Course $c) => [$c->id => $c->title . ' · ' . $c->status->getLabel()]))
                    ->searchable()
                    ->required()
                    ->live(),
                Select::make('learning_path_id')
                    ->label('Lộ trình')
                    ->options(fn (Get $get) => LearningPath::query()->where('course_id', $get('course_id'))->orderBy('sort_order')->pluck('name', 'id'))
                    ->placeholder('Để học viên trả lời câu hỏi phân loại trên app')
                    ->helperText('Để trống: app hỏi câu hỏi phân loại rồi gợi ý lộ trình. Chọn: gán thẳng lộ trình này.')
                    ->visible(fn (Get $get) => Course::find($get('course_id'))?->type === CourseType::MultiPath),
                Textarea::make('note')
                    ->label('Ghi chú')
                    ->placeholder('VD: tặng khi tham gia khoá huấn luyện 15/11')
                    ->rows(2)
                    ->maxLength(300),
            ])
            ->modalSubmitActionLabel('Mở khoá')
            ->action(function (array $data) use ($user) {
                $student = $user();
                $course = Course::findOrFail($data['course_id']);
                $path = filled($data['learning_path_id'] ?? null) ? LearningPath::where('course_id', $course->id)->find($data['learning_path_id']) : null;

                app(LearningProgress::class)->enroll($student, $course, EnrollmentSource::Admin, $path, auth()->user(), $data['note'] ?? null);

                Notification::make()->title('Đã mở khoá "' . $course->title . '" cho ' . $student->name)->success()->send();
            });
    }
}

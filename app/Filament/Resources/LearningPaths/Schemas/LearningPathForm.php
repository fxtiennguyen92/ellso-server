<?php

namespace App\Filament\Resources\LearningPaths\Schemas;

use App\Enums\CourseType;
use App\Models\Classification;
use App\Models\ClassificationOption;
use App\Models\Course;
use App\Models\LearningPath;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class LearningPathForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Thông tin lộ trình')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên lộ trình')
                            ->placeholder('VD: Marketing — Làm được')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_default')
                            ->label('Lộ trình mặc định')
                            ->helperText('Dùng khi học viên không chọn lộ trình.')
                            ->inline(false),
                        Textarea::make('description')
                            ->label('Mô tả cho học viên')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                Section::make('Áp dụng cho')
                    ->description('Chọn giá trị cho từng phân loại của khóa. Để trống một phân loại = áp dụng cho mọi giá trị. Có thể chọn nhiều giá trị.')
                    ->visible(fn ($livewire) => self::pageCourse($livewire)?->type === CourseType::MultiPath)
                    ->columns(2)
                    ->schema(fn ($livewire) => self::conditionFields(self::pageCourse($livewire))),
            ]);
    }

    /**
     * Khóa học của trang hiện tại. Lộ trình là resource lồng trong Khóa học:
     * trang Tạo lấy khóa từ bản ghi cha, trang Sửa lấy từ chính lộ trình.
     */
    public static function pageCourse(mixed $livewire): ?Course
    {
        $parent = method_exists($livewire, 'getParentRecord') ? $livewire->getParentRecord() : null;

        if ($parent instanceof Course) {
            return $parent;
        }

        $record = $livewire->record ?? null;

        return $record instanceof LearningPath ? $record->course : null;
    }

    /**
     * Một ô chọn (nhiều giá trị) cho mỗi phân loại khóa đang dùng.
     * State: conditions.{classification_id} = [option_id, …]
     */
    private static function conditionFields(?Course $course): array
    {
        if (! $course) {
            return [];
        }

        $classifications = $course->classifications()->with('options')->get();

        if ($classifications->isEmpty()) {
            return [
                Text::make('Khóa chưa chọn phân loại nào. Vào trang sửa khóa học › mục "Phân loại lộ trình" để thêm.'),
            ];
        }

        return $classifications
            ->map(fn (Classification $c) => Select::make("conditions.{$c->id}")
                ->label($c->name . ($c->isShared() ? '' : ' (riêng)'))
                ->options($c->options->pluck('name', 'id'))
                ->multiple()
                ->placeholder('Mọi giá trị'))
            ->all();
    }

    /**
     * Tìm lộ trình khác trong cùng khóa có đúng bộ điều kiện này (trùng lặp).
     *
     * @param  array<int>  $optionIds
     */
    public static function findDuplicatePath(int $courseId, array $optionIds, ?int $ignorePathId = null): ?LearningPath
    {
        $wanted = collect($optionIds)->map(fn ($id) => (int) $id)->sort()->values()->all();

        return LearningPath::query()
            ->where('course_id', $courseId)
            ->when($ignorePathId, fn ($q) => $q->whereKeyNot($ignorePathId))
            ->with('options:id')
            ->get()
            ->first(fn (LearningPath $path) => $path->options->pluck('id')->sort()->values()->all() === $wanted);
    }

    /**
     * Lấy danh sách option_id hợp lệ từ state "conditions" (chỉ phân loại khóa đang dùng).
     *
     * @return array<int>
     */
    public static function optionIdsFromState(int $courseId, array $conditions): array
    {
        $course = Course::find($courseId);
        $allowed = $course
            ? ClassificationOption::whereIn('classification_id', $course->classifications()->pluck('classifications.id'))->pluck('id')->all()
            : [];

        return collect($conditions)
            ->flatten()
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->intersect($allowed)
            ->unique()
            ->values()
            ->all();
    }
}

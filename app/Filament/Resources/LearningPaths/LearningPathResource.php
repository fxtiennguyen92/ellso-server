<?php

namespace App\Filament\Resources\LearningPaths;

use App\Filament\Resources\Courses\CourseResource;
use App\Filament\Resources\LearningPaths\Pages\BuildLearningPath;
use App\Filament\Resources\LearningPaths\Pages\CreateLearningPath;
use App\Filament\Resources\LearningPaths\Pages\EditLearningPath;
use App\Filament\Resources\LearningPaths\Schemas\LearningPathForm;
use App\Filament\Resources\LearningPaths\Tables\LearningPathsTable;
use App\Models\LearningPath;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lộ trình học — resource LỒNG trong Khóa học (không có mục riêng trên menu trái).
 * URL: /courses/{course}/learning-paths/{record}/edit | /build
 * Danh sách lộ trình của một khóa: CourseResource › trang "Lộ trình học".
 */
class LearningPathResource extends Resource
{
    protected static ?string $model = LearningPath::class;

    protected static ?string $parentResource = CourseResource::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $modelLabel = 'lộ trình';

    protected static ?string $pluralModelLabel = 'Lộ trình học';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return LearningPathForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LearningPathsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'create' => CreateLearningPath::route('/create'),
            'edit' => EditLearningPath::route('/{record}/edit'),
            'build' => BuildLearningPath::route('/{record}/build'),
        ];
    }

    /** URL các trang của lộ trình (cần cả khóa cha). */
    public static function urlFor(string $page, LearningPath $path): string
    {
        return static::getUrl($page, ['course' => $path->course_id, 'record' => $path]);
    }

    /** Giảng viên chỉ thấy lộ trình thuộc khóa học của mình. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user?->isInstructor()) {
            $query->whereHas('course', fn (Builder $q) => $q->where('instructor_id', $user->id));
        }

        return $query;
    }
}

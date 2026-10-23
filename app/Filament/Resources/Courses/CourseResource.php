<?php

namespace App\Filament\Resources\Courses;

use App\Filament\Resources\Courses\Pages\CreateCourse;
use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Pages\ManageCourseLearningPaths;
use App\Filament\Resources\Courses\Schemas\CourseForm;
use App\Filament\Resources\Courses\Tables\CoursesTable;
use App\Models\Course;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'khóa học';

    protected static ?string $pluralModelLabel = 'Khóa học';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return CourseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CoursesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourses::route('/'),
            'create' => CreateCourse::route('/create'),
            'edit' => EditCourse::route('/{record}/edit'),
            // Trang danh sách lộ trình của khóa; key = tên quan hệ dạng kebab để resource lồng LearningPath tìm thấy
            'learning-paths' => ManageCourseLearningPaths::route('/{record}/learning-paths'),
        ];
    }

    /** Giảng viên chỉ thấy khóa học của mình. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);

        $user = auth()->user();

        if ($user?->isInstructor()) {
            $query->where('instructor_id', $user->id);
        }

        return $query;
    }

    /** Giảng viên không thấy khóa học của người khác trong tìm kiếm toàn cục. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery()->withoutTrashed();
    }
}

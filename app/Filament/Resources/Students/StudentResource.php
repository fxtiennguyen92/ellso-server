<?php

namespace App\Filament\Resources\Students;

use App\Enums\UserRole;
use App\Filament\Resources\Students\Pages\ManageStudentLogs;
use App\Filament\Resources\Students\Pages\ManageStudents;
use App\Filament\Resources\Students\Pages\StudentProfile;
use App\Filament\Resources\Students\RelationManagers\AppSessionsRelationManager;
use App\Filament\Resources\Students\RelationManagers\BookPurchasesRelationManager;
use App\Filament\Resources\Students\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\Students\RelationManagers\LogsRelationManager;
use App\Filament\Resources\Students\RelationManagers\MembershipsRelationManager;
use App\Filament\Resources\Students\RelationManagers\PlacementAttemptsRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Tài khoản › Học viên: như Hệ thống › Người dùng (cùng cột, bộ lọc, thao tác) nhưng chỉ học viên,
 * thêm trang **Học bạ** — tổng quan một học viên: khoá học + tiến độ, mở khoá học, kết quả bài kiểm tra
 * đầu vào (năng lực), gói thành viên, lượt dùng app, nhật ký đăng nhập.
 */
class StudentResource extends UserResource
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string | UnitEnum | null $navigationGroup = 'Tài khoản';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Học viên';

    protected static ?string $modelLabel = 'học viên';

    protected static ?string $pluralModelLabel = 'Học viên';

    protected static ?string $slug = 'students';

    protected static array $roles = [UserRole::Student];

    public static function table(Table $table): Table
    {
        // Bấm vào học viên → Học bạ
        return parent::table($table)->recordUrl(fn (User $record) => static::getUrl('profile', ['record' => $record]));
    }

    protected static function extraRecordActions(): array
    {
        return [
            Action::make('profile')
                ->label('Học bạ')
                ->icon(Heroicon::AcademicCap)
                ->url(fn (User $record) => static::getUrl('profile', ['record' => $record])),
        ];
    }

    public static function getRelations(): array
    {
        return [
            EnrollmentsRelationManager::class,
            PlacementAttemptsRelationManager::class,
            MembershipsRelationManager::class,
            BookPurchasesRelationManager::class,
            AppSessionsRelationManager::class,
            LogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageStudents::route('/'),
            'profile' => StudentProfile::route('/{record}'),
            'logs' => ManageStudentLogs::route('/{record}/logs'),
        ];
    }
}

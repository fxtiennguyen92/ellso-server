<?php

namespace App\Filament\Resources\TrainingCourses;

use App\Enums\TrainingCourseStatus;
use App\Filament\Resources\TrainingCourses\Pages\CreateTrainingCourse;
use App\Filament\Resources\TrainingCourses\Pages\EditTrainingCourse;
use App\Filament\Resources\TrainingCourses\Pages\ListTrainingCourses;
use App\Filament\Resources\TrainingCourses\Pages\ManageTrainingRegistrations;
use App\Filament\Resources\TrainingCourses\Pages\ManageTrainingSessions;
use App\Filament\Resources\TrainingCourses\Schemas\TrainingCourseForm;
use App\Models\TrainingCourse;
use App\Support\Vnd;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use App\Support\MediaStorage;

/**
 * Đào tạo › Chương trình huấn luyện (offline). Tab: Thông tin · Lịch các buổi · Người đăng ký.
 * Một chương trình gồm nhiều buổi (buổi 1 … buổi n, mỗi buổi một địa điểm); học viên đăng ký cả chương trình;
 * số học viên tối đa + phí theo chương trình. Lớp / bảng vẫn tên TrainingCourse (training_courses).
 */
class TrainingCourseResource extends Resource
{
    protected static ?string $model = TrainingCourse::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Chương trình huấn luyện';

    protected static ?string $modelLabel = 'chương trình huấn luyện';

    protected static ?string $pluralModelLabel = 'Chương trình huấn luyện';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'trainings';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return TrainingCourseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query
                ->withCount(['activeSessions', 'registrations'])
                ->with(['trainer:id,name', 'activeSessions.location']))
            // Giảng viên bấm vào chương trình → xem lịch các buổi (không sửa thông tin)
            ->recordUrl(fn (TrainingCourse $record) => auth()->user()?->can('update', $record)
                ? EditTrainingCourse::getUrl(['record' => $record])
                : ManageTrainingSessions::getUrl(['record' => $record]))
            ->columns([
                ImageColumn::make('banner_path')
                    ->label('Banner')
                    ->disk(MediaStorage::diskName())
                    ->imageWidth(128)
                    ->imageHeight(72)
                    ->extraImgAttributes(['style' => 'border-radius: 10px; object-fit: cover;']),
                TextColumn::make('title')
                    ->label('Chương trình')
                    ->weight('medium')
                    ->description(fn (TrainingCourse $record) => $record->trainer ? 'Hướng dẫn: ' . $record->trainer->name : $record->summary)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('price')
                    ->label('Phí tham gia')
                    ->formatStateUsing(fn (int $state) => $state ? Vnd::full($state) : 'Miễn phí')
                    ->description(fn (TrainingCourse $record) => $record->price ? null : 'với gói thành viên')
                    ->sortable(),
                TextColumn::make('schedule')
                    ->label('Lịch')
                    ->state(function (TrainingCourse $record) {
                        $first = $record->activeSessions->first();

                        return $first ? $record->active_sessions_count . ' buổi · từ ' . $first->dateLabel() : 'Chưa có lịch';
                    })
                    ->description(fn (TrainingCourse $record) => ($first = $record->activeSessions->first())
                        ? 'Buổi 1 · ' . $first->location?->label
                        : null)
                    ->color(fn (TrainingCourse $record) => $record->active_sessions_count ? null : 'gray'),
                TextColumn::make('registrations_count')
                    ->label('Đăng ký')
                    ->formatStateUsing(fn (int $state, TrainingCourse $record) => $state . ' / ' . $record->capacity)
                    ->color(fn (int $state, TrainingCourse $record) => $state >= $record->capacity ? 'danger' : null)
                    ->url(fn (TrainingCourse $record) => ManageTrainingRegistrations::getUrl(['record' => $record]))
                    ->alignCenter(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(TrainingCourseStatus::class),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->label('Sửa thông tin'),
                    ReplicateAction::make()
                        ->label('Nhân bản')
                        ->excludeAttributes(['active_sessions_count', 'registrations_count'])
                        ->modalDescription('Tạo bản sao (nháp) với cùng thông tin, banner, trailer và nội dung — chưa có lịch các buổi và người đăng ký. Dùng khi muốn mở lại chương trình lần nữa.')
                        ->beforeReplicaSaved(function (TrainingCourse $replica) {
                            $replica->title = $replica->title . ' (bản sao)';
                            $replica->status = TrainingCourseStatus::Draft;
                            $replica->created_by_id = auth()->id();
                        })
                        ->successRedirectUrl(fn (TrainingCourse $replica) => ManageTrainingSessions::getUrl(['record' => $replica])),
                    DeleteAction::make()
                        ->modalDescription('Xoá chương trình cùng toàn bộ lịch các buổi và danh sách người đăng ký.'),
                ]),
            ]);
    }

    /** Giảng viên chỉ thấy chương trình mình hướng dẫn. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user?->isAdmin() ? $query : $query->where('trainer_id', $user?->id);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditTrainingCourse::class,
            ManageTrainingSessions::class,
            ManageTrainingRegistrations::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingCourses::route('/'),
            'create' => CreateTrainingCourse::route('/create'),
            'edit' => EditTrainingCourse::route('/{record}/edit'),
            'sessions' => ManageTrainingSessions::route('/{record}/sessions'),
            'registrations' => ManageTrainingRegistrations::route('/{record}/registrations'),
        ];
    }
}

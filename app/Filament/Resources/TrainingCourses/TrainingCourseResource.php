<?php

namespace App\Filament\Resources\TrainingCourses;

use App\Enums\TrainingCourseStatus;
use App\Filament\Resources\TrainingCourses\Pages\CreateTrainingCourse;
use App\Filament\Resources\TrainingCourses\Pages\EditTrainingCourse;
use App\Filament\Resources\TrainingCourses\Pages\ListTrainingCourses;
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

/**
 * Đào tạo › Khoá huấn luyện (offline). Tab: Thông tin · Lịch tổ chức.
 * 1 khoá = 1 buổi, mở vào nhiều ngày; mỗi ngày có địa điểm + số người tối đa; giá theo khoá.
 */
class TrainingCourseResource extends Resource
{
    protected static ?string $model = TrainingCourse::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Khoá huấn luyện';

    protected static ?string $modelLabel = 'khoá huấn luyện';

    protected static ?string $pluralModelLabel = 'Khoá huấn luyện';

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
                ->withCount(['upcomingSessions'])
                ->with(['trainer:id,name', 'upcomingSessions' => fn ($q) => $q->with('location')->limit(100)]))
            // Giáo viên bấm vào khoá → xem lịch tổ chức (không sửa thông tin khoá)
            ->recordUrl(fn (TrainingCourse $record) => auth()->user()?->can('update', $record)
                ? EditTrainingCourse::getUrl(['record' => $record])
                : ManageTrainingSessions::getUrl(['record' => $record]))
            ->columns([
                ImageColumn::make('banner_path')
                    ->label('Banner')
                    ->disk('public')
                    ->imageWidth(128)
                    ->imageHeight(72)
                    ->extraImgAttributes(['style' => 'border-radius: 10px; object-fit: cover;']),
                TextColumn::make('title')
                    ->label('Khoá huấn luyện')
                    ->weight('medium')
                    ->description(fn (TrainingCourse $record) => $record->trainer ? 'Hướng dẫn: ' . $record->trainer->name : $record->summary)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('price')
                    ->label('Giá / người')
                    ->formatStateUsing(fn (int $state) => $state ? Vnd::full($state) : 'Miễn phí')
                    ->sortable(),
                TextColumn::make('next_session')
                    ->label('Ngày sắp tới')
                    ->state(function (TrainingCourse $record) {
                        $next = $record->upcomingSessions->first();

                        return $next ? $next->dateLabel() . ' · ' . $next->location?->label : 'Chưa có lịch';
                    })
                    ->description(fn (TrainingCourse $record) => $record->upcoming_sessions_count > 1
                        ? 'và ' . ($record->upcoming_sessions_count - 1) . ' ngày khác'
                        : null)
                    ->color(fn (TrainingCourse $record) => $record->upcoming_sessions_count ? null : 'gray'),
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
                        ->modalDescription('Tạo bản sao (nháp) với cùng banner, giá và nội dung — chưa có lịch tổ chức.')
                        ->beforeReplicaSaved(function (TrainingCourse $replica) {
                            $replica->title = $replica->title . ' (bản sao)';
                            $replica->status = TrainingCourseStatus::Draft;
                            $replica->created_by_id = auth()->id();
                        })
                        ->successRedirectUrl(fn (TrainingCourse $replica) => EditTrainingCourse::getUrl(['record' => $replica])),
                    DeleteAction::make()
                        ->modalDescription('Xoá khoá huấn luyện cùng toàn bộ lịch tổ chức.'),
                ]),
            ]);
    }

    /** Giáo viên chỉ thấy khoá mình hướng dẫn. */
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
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingCourses::route('/'),
            'create' => CreateTrainingCourse::route('/create'),
            'edit' => EditTrainingCourse::route('/{record}/edit'),
            'sessions' => ManageTrainingSessions::route('/{record}/sessions'),
        ];
    }
}

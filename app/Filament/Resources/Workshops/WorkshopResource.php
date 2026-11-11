<?php

namespace App\Filament\Resources\Workshops;

use App\Enums\TrainingCourseStatus;
use App\Filament\Resources\Workshops\Pages\CreateWorkshop;
use App\Filament\Resources\Workshops\Pages\EditWorkshop;
use App\Filament\Resources\Workshops\Pages\ListWorkshops;
use App\Filament\Resources\Workshops\Pages\ManageWorkshopRegistrations;
use App\Filament\Resources\Workshops\Pages\ManageWorkshopSessions;
use App\Filament\Resources\Workshops\Schemas\WorkshopForm;
use App\Models\Workshop;
use App\Support\MediaStorage;
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
 * Đào tạo › Workshop (online). Tab: Thông tin · Lịch · Người đăng ký.
 * Một workshop = một buổi, lặp lại nhiều ngày / khung giờ; học viên đăng ký từng buổi; số người tối đa (tuỳ chọn) mỗi buổi.
 * Link họp (Zoom / Meet) dán tay cho từng buổi; nút "Gửi link cho người đăng ký" gửi email.
 */
class WorkshopResource extends Resource
{
    protected static ?string $model = Workshop::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedVideoCamera;

    protected static string | UnitEnum | null $navigationGroup = 'Đào tạo';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Workshop';

    protected static ?string $modelLabel = 'workshop';

    protected static ?string $pluralModelLabel = 'Workshop';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'workshops';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return WorkshopForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query
                ->withCount(['upcomingSessions', 'registrations'])
                ->with(['trainer:id,name', 'upcomingSessions']))
            ->recordUrl(fn (Workshop $record) => auth()->user()?->can('update', $record)
                ? EditWorkshop::getUrl(['record' => $record])
                : ManageWorkshopSessions::getUrl(['record' => $record]))
            ->columns([
                ImageColumn::make('banner_path')
                    ->label('Banner')
                    ->disk(MediaStorage::diskName())
                    ->imageWidth(128)
                    ->imageHeight(72)
                    ->extraImgAttributes(['style' => 'border-radius: 10px; object-fit: cover;']),
                TextColumn::make('title')
                    ->label('Workshop')
                    ->weight('medium')
                    ->description(fn (Workshop $record) => $record->trainer ? 'Hướng dẫn: ' . $record->trainer->name : $record->summary)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('price')
                    ->label('Phí / buổi')
                    ->formatStateUsing(fn (int $state) => $state ? Vnd::full($state) : 'Miễn phí')
                    ->description(fn (Workshop $record) => $record->price ? null : 'với gói thành viên')
                    ->sortable(),
                TextColumn::make('capacity')
                    ->label('Tối đa / buổi')
                    ->formatStateUsing(fn (?int $state) => $state ? $state . ' người' : 'Không giới hạn')
                    ->placeholder('Không giới hạn'),
                TextColumn::make('next_session')
                    ->label('Buổi sắp tới')
                    ->state(function (Workshop $record) {
                        $next = $record->upcomingSessions->first();

                        return $next ? $next->dateLabel() . ' · ' . $next->timeLabel() : 'Chưa có lịch';
                    })
                    ->description(fn (Workshop $record) => $record->upcoming_sessions_count > 1
                        ? 'và ' . ($record->upcoming_sessions_count - 1) . ' buổi khác'
                        : null)
                    ->color(fn (Workshop $record) => $record->upcoming_sessions_count ? null : 'gray'),
                TextColumn::make('registrations_count')
                    ->label('Đăng ký')
                    ->url(fn (Workshop $record) => ManageWorkshopRegistrations::getUrl(['record' => $record]))
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
                        ->excludeAttributes(['upcoming_sessions_count', 'registrations_count'])
                        ->modalDescription('Tạo bản sao (nháp) với cùng thông tin, banner và nội dung — chưa có lịch và người đăng ký.')
                        ->beforeReplicaSaved(function (Workshop $replica) {
                            $replica->title = $replica->title . ' (bản sao)';
                            $replica->status = TrainingCourseStatus::Draft;
                            $replica->created_by_id = auth()->id();
                        })
                        ->successRedirectUrl(fn (Workshop $replica) => EditWorkshop::getUrl(['record' => $replica])),
                    DeleteAction::make()
                        ->modalDescription('Xoá workshop cùng toàn bộ lịch và danh sách người đăng ký.'),
                ]),
            ]);
    }

    /** Giảng viên chỉ thấy workshop mình hướng dẫn. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user?->isAdmin() ? $query : $query->where('trainer_id', $user?->id);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditWorkshop::class,
            ManageWorkshopSessions::class,
            ManageWorkshopRegistrations::class,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkshops::route('/'),
            'create' => CreateWorkshop::route('/create'),
            'edit' => EditWorkshop::route('/{record}/edit'),
            'sessions' => ManageWorkshopSessions::route('/{record}/sessions'),
            'registrations' => ManageWorkshopRegistrations::route('/{record}/registrations'),
        ];
    }
}

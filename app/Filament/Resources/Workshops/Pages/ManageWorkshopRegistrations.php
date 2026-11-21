<?php

namespace App\Filament\Resources\Workshops\Pages;

use App\Enums\RegistrationSource;
use App\Exceptions\RegistrationRefused;
use App\Filament\Resources\Workshops\WorkshopResource;
use App\Filament\Support\RegistrantFields;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopRegistration;
use App\Models\WorkshopSession;
use App\Services\EventRegistration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tab "Người đăng ký": người đăng ký từng buổi workshop. Lọc theo buổi (mở từ tab Lịch: ?session=id).
 * Admin thêm (chọn buổi + học viên) / huỷ; giảng viên xem của workshop mình hướng dẫn.
 * Điểm danh (workshop online): người hướng dẫn / admin tự đánh dấu "Có mặt" từng người (hoặc chọn nhiều) — cột Điểm danh.
 */
class ManageWorkshopRegistrations extends ManageRelatedRecords
{
    protected static string $resource = WorkshopResource::class;

    protected static string $relationship = 'registrations';

    protected static ?string $navigationLabel = 'Người đăng ký';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $title = 'Người đăng ký';

    protected static ?string $breadcrumb = 'Người đăng ký';

    protected static ?string $modelLabel = 'người đăng ký';

    public function getSubheading(): ?string
    {
        $workshop = $this->workshop();

        return $workshop->title.' · '.($workshop->hasCapacity() ? 'tối đa '.$workshop->capacity.' người mỗi buổi' : 'không giới hạn số người');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'createdBy:id,name', 'session', 'attendanceMarkedBy:id,name']))
            ->emptyStateHeading('Chưa có ai đăng ký')
            ->emptyStateDescription('Học viên có gói thành viên tự đăng ký buổi trên app (workshop miễn phí), hoặc admin thêm tay — VD đã chuyển khoản.')
            ->columns([
                TextColumn::make('session.starts_at')
                    ->label('Buổi')
                    ->formatStateUsing(fn (WorkshopRegistration $record) => $record->session?->dateLabel())
                    ->description(fn (WorkshopRegistration $record) => $record->session?->timeLabel()
                        .($record->session?->isCancelled() ? ' · đã huỷ' : ''))
                    ->sortable(),
                ...RegistrantFields::columns(),
                TextColumn::make('attended_at')
                    ->label('Điểm danh')
                    ->state(fn (WorkshopRegistration $r) => $r->attended_at ? 'Có mặt' : 'Chưa')
                    ->description(fn (WorkshopRegistration $r) => $r->attended_at
                        ? $r->attended_at->timezone(WorkshopSession::TZ)->format('H:i d/m').($r->attendanceMarkedBy ? ' · '.$r->attendanceMarkedBy->name : '')
                        : null)
                    ->badge()
                    ->color(fn (string $state) => $state === 'Có mặt' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('session')
                    ->label('Buổi')
                    ->options(fn () => $this->sessionOptions(false))
                    ->default(fn () => request()->query('session'))
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('workshop_registrations.workshop_session_id', $data['value'])
                        : $query),
                SelectFilter::make('source')
                    ->label('Nguồn')
                    ->options(RegistrationSource::class)
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->where('workshop_registrations.source', $data['value'])
                        : $query),
            ])
            ->headerActions([
                Action::make('addRegistrant')
                    ->label('Thêm người đăng ký')
                    ->icon(Heroicon::UserPlus)
                    ->color('primary')
                    ->authorize('create', WorkshopRegistration::class)
                    ->modalHeading('Thêm người đăng ký')
                    ->modalDescription('Không cần thanh toán trên app (VD: đã chuyển khoản). Vẫn tính vào số chỗ của buổi.')
                    ->modalWidth('lg')
                    ->schema([
                        Select::make('workshop_session_id')
                            ->label('Buổi')
                            ->options(fn () => $this->sessionOptions(true))
                            ->default(fn () => $this->tableFilters['session']['value'] ?? null)
                            ->required(),
                        ...RegistrantFields::form(),
                    ])
                    ->action(function (array $data, Action $action) {
                        $session = WorkshopSession::query()->where('workshop_id', $this->workshop()->id)->findOrFail($data['workshop_session_id']);
                        try {
                            app(EventRegistration::class)->registerWorkshop(
                                User::findOrFail($data['user_id']), $session, RegistrationSource::Admin, auth()->user(), $data['note'] ?? null,
                            );
                        } catch (RegistrationRefused $e) {
                            Notification::make()->title($e->reason === 'membership_required'
                                ? 'Học viên chưa có gói thành viên còn hạn — cấp gói ở Học bạ trước.'
                                : ($e->reason === 'full' ? 'Buổi này đã đủ số người tối đa.' : $e->getMessage()))
                                ->danger()->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->title('Đã thêm người đăng ký')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('markAttended')
                    ->label('Điểm danh có mặt')
                    ->icon(Heroicon::CheckCircle)
                    ->action(function (Collection $records) {
                        $records->whereNull('attended_at')->each->update(['attended_at' => now(), 'attendance_marked_by_id' => auth()->id()]);
                        Notification::make()->title('Đã điểm danh '.$records->count().' người')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->recordActions([
                Action::make('attend')
                    ->label('Có mặt')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->visible(fn (WorkshopRegistration $r) => ! $r->attended_at && $r->session?->starts_at->isPast())
                    ->action(fn (WorkshopRegistration $r) => $r->update(['attended_at' => now(), 'attendance_marked_by_id' => auth()->id()])),
                Action::make('unattend')
                    ->label('Bỏ điểm danh')
                    ->icon(Heroicon::XCircle)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (WorkshopRegistration $r) => (bool) $r->attended_at)
                    ->action(fn (WorkshopRegistration $r) => $r->update(['attended_at' => null, 'attendance_marked_by_id' => null])),
                DeleteAction::make()
                    ->label('Huỷ đăng ký')
                    ->modalHeading('Huỷ đăng ký')
                    ->modalDescription(fn (WorkshopRegistration $record) => 'Huỷ đăng ký của '.$record->user?->name.' ở buổi '.$record->session?->dateLabel().'.'),
            ]);
    }

    /** @return array<int, string> */
    private function sessionOptions(bool $openOnly): array
    {
        return $this->workshop()->sessions()
            ->when($openOnly, fn ($q) => $q->whereNull('cancelled_at')->where('ends_at', '>', now()))
            ->get()
            ->mapWithKeys(fn (WorkshopSession $s) => [$s->id => $s->dateLabel().' · '.$s->timeLabel().($s->isCancelled() ? ' (đã huỷ)' : '')])
            ->all();
    }

    private function workshop(): Workshop
    {
        /** @var Workshop */
        return $this->getOwnerRecord();
    }
}

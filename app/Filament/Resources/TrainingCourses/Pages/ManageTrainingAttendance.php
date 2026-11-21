<?php

namespace App\Filament\Resources\TrainingCourses\Pages;

use App\Filament\Resources\TrainingCourses\TrainingCourseResource;
use App\Models\TrainingAttendance;
use App\Models\TrainingCourse;
use App\Models\TrainingRegistration;
use App\Models\TrainingSession;
use BackedEnum;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * Tab "Điểm danh" của chương trình huấn luyện:
 * - Nút "Mã QR điểm danh": MỘT mã QR cho cả chương trình (in / chiếu ở lớp). Học viên quét bằng app để tự điểm danh;
 *   mã chỉ có hiệu lực trong ngày có buổi học (giờ Việt Nam) và ghi vào buổi của ngày đó.
 * - Bảng học viên đã đăng ký × buổi đang chọn: giờ đến + cách điểm danh (tự quét / quét vé / điểm danh tay); đánh dấu tay
 *   hoặc bỏ điểm danh. Giảng viên của chương trình và admin đều dùng được.
 */
class ManageTrainingAttendance extends ManageRelatedRecords
{
    protected static string $resource = TrainingCourseResource::class;

    protected static string $relationship = 'registrations';

    protected static ?string $navigationLabel = 'Điểm danh';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?string $title = 'Điểm danh';

    protected static ?string $breadcrumb = 'Điểm danh';

    protected static ?string $modelLabel = 'học viên';

    public function getSubheading(): ?string
    {
        $course = $this->course();
        $today = $course->sessionOn();
        $numbers = $course->sessionNumbers();

        return $course->title.' · '.($today
            ? 'Hôm nay có Buổi '.($numbers[$today->id] ?? '?').' ('.$today->timeLabel().') — mã QR điểm danh đang có hiệu lực.'
            : 'Hôm nay không có buổi — mã QR điểm danh chỉ dùng được trong ngày có buổi.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('qr')
                ->label('Mã QR điểm danh')
                ->icon(Heroicon::QrCode)
                ->color('primary')
                ->modalHeading(fn () => 'Mã QR điểm danh — '.$this->course()->title)
                ->modalDescription('Một mã cho cả chương trình. In ra hoặc chiếu lên màn hình ở lớp; học viên mở app › Chương trình huấn luyện › Thông tin vé › Quét mã điểm danh. Mã chỉ có hiệu lực trong ngày có buổi học.')
                ->modalContent(fn () => new HtmlString(
                    '<div style="display:flex;flex-direction:column;align-items:center;gap:12px">'
                    .'<img alt="Mã QR điểm danh" style="width:320px;height:320px;image-rendering:pixelated" src="'.e($this->qrDataUri($this->course()->checkinQrValue())).'">'
                    .'<div style="font-size:12px;color:#64748b;text-align:center">Mã nhập tay (khi điện thoại không quét được):<br><code style="user-select:all;word-break:break-all">'.e($this->course()->checkinQrValue()).'</code></div>'
                    .'<div style="font-size:12px;color:#64748b">Mở trang này trên máy chiếu hoặc bấm Ctrl+P để in.</div></div>'
                ))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng'),
        ];
    }

    public function table(Table $table): Table
    {
        $sessions = $this->course()->activeSessions()->get();
        $numbers = $this->course()->sessionNumbers();

        return $table
            ->defaultSort('created_at')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'attendances.checkedBy']))
            ->emptyStateHeading('Chưa có ai đăng ký')
            ->emptyStateDescription('Học viên đăng ký ở tab Người đăng ký sẽ hiện ở đây để điểm danh.')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Học viên')
                    ->description(fn (TrainingRegistration $r) => $r->user?->email)
                    ->weight('medium')
                    ->searchable(),
                TextColumn::make('ticket_code')->label('Mã vé')->fontFamily('mono')->copyable()->toggleable(),
                TextColumn::make('present')
                    ->label('Buổi đang chọn')
                    ->state(function (TrainingRegistration $r) {
                        $a = $r->attendances->firstWhere('training_session_id', $this->selectedSessionId());

                        return $a ? 'Có mặt '.$a->checked_in_at->timezone(TrainingSession::TZ)->format('H:i') : 'Chưa điểm danh';
                    })
                    ->description(function (TrainingRegistration $r) {
                        $a = $r->attendances->firstWhere('training_session_id', $this->selectedSessionId());

                        return $a ? (TrainingAttendance::METHOD_LABELS[$a->method] ?? $a->method).($a->checkedBy ? ' · '.$a->checkedBy->name : '') : null;
                    })
                    ->badge()
                    ->color(fn (string $state) => str_starts_with($state, 'Có mặt') ? 'success' : 'gray'),
                TextColumn::make('attended_count')
                    ->label('Tổng có mặt')
                    ->state(fn (TrainingRegistration $r) => $r->attendances->count().' / '.count($numbers).' buổi'),
            ])
            ->filters([
                SelectFilter::make('session')
                    ->label('Buổi')
                    ->options($sessions->mapWithKeys(fn (TrainingSession $s) => [$s->id => 'Buổi '.($numbers[$s->id] ?? '?').' · '.$s->dateLabel()])->all())
                    ->default(fn () => $this->defaultSessionId())
                    ->selectablePlaceholder(false)
                    ->query(fn ($query) => $query), // chỉ đổi buổi đang xem, không lọc danh sách
            ])
            ->recordActions([
                Action::make('mark')
                    ->label('Điểm danh')
                    ->icon(Heroicon::CheckCircle)
                    ->color('success')
                    ->visible(fn (TrainingRegistration $r) => ! $r->attendances->contains('training_session_id', $this->selectedSessionId()))
                    ->action(fn (TrainingRegistration $r) => $this->mark(new Collection([$r]))),
                Action::make('unmark')
                    ->label('Bỏ điểm danh')
                    ->icon(Heroicon::XCircle)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (TrainingRegistration $r) => $r->attendances->contains('training_session_id', $this->selectedSessionId()))
                    ->action(function (TrainingRegistration $r) {
                        $r->attendances()->where('training_session_id', $this->selectedSessionId())->delete();
                        Notification::make()->title('Đã bỏ điểm danh')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('markMany')
                    ->label('Điểm danh các học viên đã chọn')
                    ->icon(Heroicon::CheckCircle)
                    ->action(fn (Collection $records) => $this->mark($records))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /** Điểm danh tay cho buổi đang chọn (bỏ qua người đã có mặt). */
    private function mark(Collection $registrations): void
    {
        $sessionId = $this->selectedSessionId();
        if (! $sessionId) {
            Notification::make()->title('Chưa chọn buổi')->warning()->send();

            return;
        }
        foreach ($registrations as $r) {
            TrainingAttendance::query()->firstOrCreate(
                ['training_registration_id' => $r->id, 'training_session_id' => $sessionId],
                ['checked_in_at' => now(), 'method' => TrainingAttendance::MANUAL, 'checked_by_id' => auth()->id()],
            );
        }
        Notification::make()->title('Đã điểm danh '.$registrations->count().' học viên')->success()->send();
    }

    private function selectedSessionId(): ?int
    {
        $value = $this->getTableFilterState('session')['value'] ?? null;

        return filled($value) ? (int) $value : $this->defaultSessionId();
    }

    /** Mặc định: buổi hôm nay; không có thì buổi gần nhất đã diễn ra; chưa có buổi nào diễn ra thì buổi 1. */
    private function defaultSessionId(): ?int
    {
        $course = $this->course();
        $sessions = $course->activeSessions()->get();

        return $course->sessionOn()?->id
            ?? $sessions->filter(fn (TrainingSession $s) => $s->starts_at->isPast())->last()?->id
            ?? $sessions->first()?->id;
    }

    private function qrDataUri(string $value): string
    {
        return (new QRCode(new QROptions(['outputType' => 'svg', 'eccLevel' => EccLevel::M, 'scale' => 10, 'addQuietzone' => true])))->render($value);
    }

    private function course(): TrainingCourse
    {
        /** @var TrainingCourse */
        return $this->getOwnerRecord();
    }
}

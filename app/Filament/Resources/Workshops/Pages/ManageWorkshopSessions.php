<?php

namespace App\Filament\Resources\Workshops\Pages;

use App\Filament\Resources\Workshops\WorkshopResource;
use App\Mail\WorkshopLinkMail;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Support\Vnd;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Mail;

/**
 * Tab "Lịch": các buổi của workshop — ngày, giờ (giờ Việt Nam), link họp (Zoom / Meet, dán tay), ghi chú.
 * Mỗi buổi: xem người đăng ký, "Gửi link cho người đăng ký" (email), huỷ buổi.
 */
class ManageWorkshopSessions extends ManageRelatedRecords
{
    protected static string $resource = WorkshopResource::class;

    protected static string $relationship = 'sessions';

    protected static ?string $navigationLabel = 'Lịch';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $title = 'Lịch workshop';

    protected static ?string $breadcrumb = 'Lịch';

    protected static ?string $modelLabel = 'buổi';

    public function getSubheading(): ?string
    {
        $workshop = $this->workshop();

        return $workshop->title . ' · ' . ($workshop->price ? Vnd::full($workshop->price) . ' / buổi' : 'Miễn phí với gói thành viên')
            . ' · ' . ($workshop->hasCapacity() ? 'Tối đa ' . $workshop->capacity . ' người / buổi' : 'Không giới hạn số người')
            . ($workshop->trainer ? ' · Hướng dẫn: ' . $workshop->trainer->name : '');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                DatePicker::make('date')
                    ->label('Ngày')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->required(),
                TimePicker::make('start_time')
                    ->label('Bắt đầu')
                    ->native(false)
                    ->displayFormat('H:i')
                    ->seconds(false)
                    ->required(),
                TimePicker::make('end_time')
                    ->label('Kết thúc')
                    ->native(false)
                    ->displayFormat('H:i')
                    ->seconds(false)
                    ->required()
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                        if ($value && $get('start_time') && substr((string) $value, 0, 5) <= substr((string) $get('start_time'), 0, 5)) {
                            $fail('Giờ kết thúc phải sau giờ bắt đầu.');
                        }
                    }),
            ]),
            TextInput::make('meeting_url')
                ->label('Link họp (Zoom / Google Meet)')
                ->placeholder('https://zoom.us/j/… hoặc https://meet.google.com/…')
                ->url()
                ->maxLength(500)
                ->helperText('Có thể thêm sau. App chỉ hiện link cho người đã đăng ký, từ ' . WorkshopSession::LINK_OPENS_MINUTES_BEFORE . ' phút trước giờ bắt đầu.')
                ->columnSpanFull(),
            TextInput::make('note')
                ->label('Ghi chú cho buổi này')
                ->placeholder('VD: Chuẩn bị sẵn một bài thuyết trình 3 phút')
                ->maxLength(300)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->modifyQueryUsing(fn ($query) => $query->withCount('registrations'))
            ->emptyStateHeading('Chưa có buổi nào')
            ->emptyStateDescription('Thêm các ngày / khung giờ tổ chức workshop. Mỗi buổi có link họp riêng (hoặc dùng chung một link).')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Ngày')
                    ->formatStateUsing(fn (WorkshopSession $record) => $record->dateLabel())
                    ->description(fn (WorkshopSession $record) => $record->timeLabel())
                    ->weight('medium')
                    ->sortable(),
                TextColumn::make('meeting_url')
                    ->label('Link họp')
                    ->limit(36)
                    ->url(fn (WorkshopSession $record) => $record->meeting_url, shouldOpenInNewTab: true)
                    ->copyable()
                    ->placeholder('Chưa có link')
                    ->description(fn (WorkshopSession $record) => $record->link_sent_at
                        ? 'Đã gửi email ' . $record->link_sent_at->timezone(WorkshopSession::TZ)->format('H:i d/m') . ' · ' . $record->link_sent_count . ' người'
                        : null),
                TextColumn::make('registrations_count')
                    ->label('Đăng ký')
                    ->formatStateUsing(fn (int $state) => $this->workshop()->hasCapacity() ? $state . ' / ' . $this->workshop()->capacity : (string) $state)
                    ->color(fn (int $state) => $this->workshop()->hasCapacity() && $state >= $this->workshop()->capacity ? 'danger' : null)
                    ->url(fn (WorkshopSession $record) => ManageWorkshopRegistrations::getUrl(['record' => $this->workshop(), 'session' => $record->id]))
                    ->alignCenter(),
                TextColumn::make('state')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (WorkshopSession $record) => $record->stateLabel())
                    ->color(fn (WorkshopSession $record) => $record->stateColor())
                    ->description(fn (WorkshopSession $record) => $record->note),
            ])
            ->filters([
                TernaryFilter::make('upcoming')
                    ->label('Thời gian')
                    ->placeholder('Tất cả')
                    ->trueLabel('Sắp diễn ra')
                    ->falseLabel('Đã qua / đã huỷ')
                    ->queries(
                        true: fn ($query) => $query->upcoming(),
                        false: fn ($query) => $query->where(fn ($q) => $q->whereNotNull('cancelled_at')->orWhere('starts_at', '<=', now())),
                    ),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm buổi')
                    ->icon(Heroicon::Plus)
                    ->color('primary')
                    ->modalHeading('Thêm buổi workshop')
                    ->modalWidth('3xl')
                    ->fillForm(fn () => $this->defaultsFromLastSession())
                    ->mutateDataUsing(fn (array $data) => self::toRecord($data))
                    ->createAnother(true),
            ])
            ->recordActions([
                Action::make('sendLink')
                    ->authorize('update')
                    ->label('Gửi link')
                    ->tooltip('Gửi email link họp cho người đã đăng ký buổi này')
                    ->icon(Heroicon::PaperAirplane)
                    ->color('primary')
                    ->iconButton()
                    ->disabled(fn (WorkshopSession $record) => ! $record->meeting_url || ! $record->registrations_count || $record->isCancelled())
                    ->requiresConfirmation()
                    ->modalHeading('Gửi link cho người đăng ký')
                    ->modalDescription(fn (WorkshopSession $record) => 'Gửi email link họp ' . $record->meeting_url . ' tới ' . $record->registrations_count
                        . ' người đã đăng ký buổi ' . $record->timeLabel() . ', ' . $record->dateLabel() . '.'
                        . ($record->link_sent_at ? ' (Đã gửi lần trước lúc ' . $record->link_sent_at->timezone(WorkshopSession::TZ)->format('H:i d/m') . '.)' : '')
                        . ' Nội dung email sửa ở Hệ thống › Mẫu email › "Link tham gia workshop".')
                    ->modalSubmitActionLabel('Gửi email')
                    ->action(fn (WorkshopSession $record) => $this->sendLink($record)),
                ActionGroup::make([
                    EditAction::make()
                        ->modalHeading(fn (WorkshopSession $record) => 'Sửa buổi ' . $record->dateLabel())
                        ->modalWidth('3xl')
                        ->mutateRecordDataUsing(fn (array $data, WorkshopSession $record) => [...$data, ...self::toForm($record)])
                        ->mutateDataUsing(fn (array $data) => self::toRecord($data)),
                    Action::make('registrants')
                        ->label('Xem người đăng ký')
                        ->icon(Heroicon::Users)
                        ->url(fn (WorkshopSession $record) => ManageWorkshopRegistrations::getUrl(['record' => $this->workshop(), 'session' => $record->id])),
                    Action::make('repeat')
                        ->authorize('create', WorkshopSession::class)
                        ->label('Thêm buổi tương tự')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->modalHeading('Thêm buổi tương tự')
                        ->modalDescription(fn (WorkshopSession $record) => 'Cùng giờ (' . $record->timeLabel() . ') và cùng link họp. Chỉ cần chọn ngày — sửa link sau nếu mỗi buổi một link.')
                        ->modalWidth('md')
                        ->schema([
                            DatePicker::make('date')->label('Ngày')->native(false)->displayFormat('d/m/Y')->required(),
                        ])
                        ->action(function (array $data, WorkshopSession $record) {
                            $copy = $record->replicate(['cancelled_at', 'link_sent_at', 'link_sent_count', 'registrations_count']);
                            $copy->fill(self::toRecord([...self::toForm($record), 'date' => $data['date']]));
                            $copy->save();
                            Notification::make()->title('Đã thêm buổi ' . $copy->dateLabel())->success()->send();
                        }),
                    Action::make('cancel')
                        ->authorize('update')
                        ->label(fn (WorkshopSession $record) => $record->isCancelled() ? 'Mở lại buổi này' : 'Huỷ buổi này')
                        ->icon(fn (WorkshopSession $record) => $record->isCancelled() ? Heroicon::ArrowUturnLeft : Heroicon::XCircle)
                        ->color(fn (WorkshopSession $record) => $record->isCancelled() ? 'gray' : 'danger')
                        ->requiresConfirmation()
                        ->modalDescription(fn (WorkshopSession $record) => $record->isCancelled()
                            ? 'Buổi này sẽ hiện lại trên app.'
                            : 'Buổi này ẩn khỏi app nhưng vẫn giữ trong lịch (cùng danh sách người đăng ký) để tra cứu. Hãy báo cho người đã đăng ký.')
                        ->action(fn (WorkshopSession $record) => $record->update(['cancelled_at' => $record->isCancelled() ? null : now()])),
                    DeleteAction::make()
                        ->modalDescription('Xoá buổi này cùng danh sách người đăng ký của buổi.'),
                ]),
            ]);
    }

    /** Email link họp cho mọi người đăng ký buổi (qua queue), ghi lại thời điểm + số người. */
    private function sendLink(WorkshopSession $session): void
    {
        $session->loadMissing('registrations.user');
        $workshop = $this->workshop();
        $when = $session->whenLabel();
        $sent = 0;

        foreach ($session->registrations as $registration) {
            if (! $registration->user?->email) {
                continue;
            }
            Mail::to($registration->user->email)->queue(
                WorkshopLinkMail::forRecipient($registration->user, $workshop->title, $when, $session->meeting_url),
            );
            $sent++;
        }

        $session->update(['link_sent_at' => now(), 'link_sent_count' => $sent]);
        Notification::make()->title('Đã gửi link cho ' . $sent . ' người')->success()->send();
    }

    private function workshop(): Workshop
    {
        /** @var Workshop */
        return $this->getOwnerRecord();
    }

    /** Buổi mới lấy sẵn giờ + link của buổi gần nhất. */
    private function defaultsFromLastSession(): array
    {
        $last = $this->workshop()->sessions()->reorder()->latest('starts_at')->first();

        return $last
            ? [...self::toForm($last), 'date' => null, 'note' => null]
            : ['start_time' => '19:00', 'end_time' => '21:00'];
    }

    /** Bản ghi → trường form (ngày, giờ theo giờ Việt Nam). */
    private static function toForm(WorkshopSession $record): array
    {
        $start = $record->starts_at->timezone(WorkshopSession::TZ);
        $end = $record->ends_at->timezone(WorkshopSession::TZ);

        return [
            'date' => $start->toDateString(),
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'meeting_url' => $record->meeting_url,
            'note' => $record->note,
        ];
    }

    /** Trường form → cột (starts_at / ends_at theo UTC). */
    private static function toRecord(array $data): array
    {
        $date = CarbonImmutable::parse($data['date'], WorkshopSession::TZ)->toDateString();
        $at = fn (string $time) => CarbonImmutable::parse($date . ' ' . substr($time, 0, 5), WorkshopSession::TZ)->utc();

        return [
            'starts_at' => $at($data['start_time']),
            'ends_at' => $at($data['end_time']),
            'meeting_url' => filled($data['meeting_url'] ?? null) ? trim($data['meeting_url']) : null,
            'note' => $data['note'] ?? null,
        ];
    }
}

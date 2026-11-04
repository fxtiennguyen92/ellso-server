<?php

namespace App\Filament\Resources\TrainingCourses\Pages;

use App\Filament\Resources\TrainingCourses\TrainingCourseResource;
use App\Filament\Resources\TrainingLocations\TrainingLocationForm;
use App\Models\TrainingCourse;
use App\Models\TrainingLocation;
use App\Models\TrainingSession;
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
use Filament\Forms\Components\Select;
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

/**
 * Tab "Lịch các buổi": buổi 1 … buổi n của chương trình — ngày, giờ, địa điểm (chọn từ sổ địa chỉ hoặc lưu mới), ghi chú.
 * Buổi mới lấy sẵn giờ + địa điểm của buổi gần nhất; "Thêm buổi tương tự" chỉ cần chọn ngày.
 * Form nhập ngày + giờ theo giờ Việt Nam; lưu starts_at / ends_at (UTC).
 */
class ManageTrainingSessions extends ManageRelatedRecords
{
    protected static string $resource = TrainingCourseResource::class;

    protected static string $relationship = 'sessions';

    protected static ?string $navigationLabel = 'Lịch các buổi';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $title = 'Lịch các buổi';

    protected static ?string $breadcrumb = 'Lịch các buổi';

    protected static ?string $modelLabel = 'buổi';

    /** [session_id => số thứ tự buổi] cho bảng. */
    private ?array $numbers = null;

    public function getSubheading(): ?string
    {
        $course = $this->course();

        return $course->title . ' · ' . ($course->price ? Vnd::full($course->price) . ' / người' : 'Miễn phí với gói thành viên')
            . ' · Tối đa ' . $course->capacity . ' học viên'
            . ($course->trainer ? ' · Hướng dẫn: ' . $course->trainer->name : '');
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
            Grid::make(['default' => 1, 'md' => 3])->columnSpanFull()->schema([
                Select::make('training_location_id')
                    ->label('Địa điểm')
                    ->options(fn () => self::locationOptions())
                    ->searchable()
                    ->required()
                    ->helperText('Mặc định là địa điểm của buổi gần nhất. Chọn địa điểm đã lưu khác, hoặc bấm + để lưu địa điểm mới (kèm nhãn) và dùng lại cho các buổi sau.')
                    ->createOptionForm(TrainingLocationForm::fields())
                    ->createOptionModalHeading('Lưu địa điểm mới')
                    ->createOptionUsing(fn (array $data) => TrainingLocation::create($data)->getKey())
                    ->columnSpanFull(),
            ]),
            TextInput::make('note')
                ->label('Ghi chú cho buổi này')
                ->placeholder('VD: Mang theo laptop')
                ->maxLength(300)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->modifyQueryUsing(function ($query) {
                $this->numbers = null; // đánh số lại mỗi lần vẽ bảng (sau khi thêm / huỷ buổi)

                return $query->with('location');
            })
            ->emptyStateHeading('Chưa có buổi nào')
            ->emptyStateDescription('Thêm buổi 1, buổi 2… của chương trình: ngày giờ và địa điểm. Các buổi thường cùng địa điểm, cũng có thể khác.')
            ->columns([
                TextColumn::make('number')
                    ->label('Buổi')
                    ->state(fn (TrainingSession $record) => ($n = $this->numbers()[$record->id] ?? null) ? 'Buổi ' . $n : '—')
                    ->weight('medium'),
                TextColumn::make('starts_at')
                    ->label('Ngày')
                    ->formatStateUsing(fn (TrainingSession $record) => $record->dateLabel())
                    ->description(fn (TrainingSession $record) => $record->timeLabel())
                    ->weight('medium')
                    ->sortable(),
                TextColumn::make('location.label')
                    ->label('Địa điểm')
                    ->description(fn (TrainingSession $record) => $record->location?->address)
                    ->url(fn (TrainingSession $record) => $record->location?->map_url, shouldOpenInNewTab: true)
                    ->wrap(),
                TextColumn::make('state')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (TrainingSession $record) => $record->stateLabel())
                    ->color(fn (TrainingSession $record) => $record->stateColor())
                    ->description(fn (TrainingSession $record) => $record->note),
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
                    ->modalHeading(fn () => 'Thêm buổi ' . ($this->course()->activeSessions()->count() + 1))
                    ->modalWidth('3xl')
                    ->fillForm(fn () => $this->defaultsFromLastSession())
                    ->mutateDataUsing(fn (array $data) => self::toRecord($data))
                    ->createAnother(true),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->modalHeading(fn (TrainingSession $record) => 'Sửa buổi ' . $record->dateLabel())
                        ->modalWidth('3xl')
                        ->mutateRecordDataUsing(fn (array $data, TrainingSession $record) => [...$data, ...self::toForm($record)])
                        ->mutateDataUsing(fn (array $data) => self::toRecord($data)),
                    Action::make('repeat')
                        ->authorize('create', TrainingSession::class)
                        ->label('Thêm buổi tương tự')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->modalHeading('Thêm buổi tương tự')
                        ->modalDescription(fn (TrainingSession $record) => 'Cùng giờ (' . $record->timeLabel() . ') và địa điểm (' . $record->location?->label . '). Chỉ cần chọn ngày.')
                        ->modalWidth('md')
                        ->schema([
                            DatePicker::make('date')->label('Ngày')->native(false)->displayFormat('d/m/Y')->required(),
                        ])
                        ->action(function (array $data, TrainingSession $record) {
                            $copy = $record->replicate(['cancelled_at']);
                            $copy->fill(self::toRecord([...self::toForm($record), 'date' => $data['date']]));
                            $copy->save();
                            Notification::make()->title('Đã thêm buổi ' . $copy->dateLabel())->success()->send();
                        }),
                    Action::make('cancel')
                        ->authorize('update')
                        ->label(fn (TrainingSession $record) => $record->isCancelled() ? 'Mở lại buổi này' : 'Huỷ buổi này')
                        ->icon(fn (TrainingSession $record) => $record->isCancelled() ? Heroicon::ArrowUturnLeft : Heroicon::XCircle)
                        ->color(fn (TrainingSession $record) => $record->isCancelled() ? 'gray' : 'danger')
                        ->requiresConfirmation()
                        ->modalDescription(fn (TrainingSession $record) => $record->isCancelled()
                            ? 'Buổi này sẽ hiện lại trên app và được đánh số lại.'
                            : 'Buổi này ẩn khỏi app (các buổi sau được đánh số lại) nhưng vẫn giữ trong lịch để tra cứu.')
                        ->action(fn (TrainingSession $record) => $record->update(['cancelled_at' => $record->isCancelled() ? null : now()])),
                    DeleteAction::make(),
                ]),
            ]);
    }

    private function course(): TrainingCourse
    {
        /** @var TrainingCourse */
        return $this->getOwnerRecord();
    }

    /** @return array<int, int> */
    private function numbers(): array
    {
        return $this->numbers ??= $this->course()->sessionNumbers();
    }

    /** Buổi mới lấy sẵn giờ + địa điểm của buổi gần nhất. */
    private function defaultsFromLastSession(): array
    {
        $last = $this->course()->sessions()->latest('starts_at')->first();

        return $last
            ? [...self::toForm($last), 'date' => null, 'note' => null]
            : ['start_time' => '18:00', 'end_time' => '21:00'];
    }

    private static function locationOptions(): array
    {
        return TrainingLocation::query()->orderBy('label')->get()
            ->mapWithKeys(fn (TrainingLocation $l) => [$l->id => $l->optionLabel()])
            ->all();
    }

    /** Bản ghi → trường form (ngày, giờ theo giờ Việt Nam). */
    private static function toForm(TrainingSession $record): array
    {
        $start = $record->starts_at->timezone(TrainingSession::TZ);
        $end = $record->ends_at->timezone(TrainingSession::TZ);

        return [
            'date' => $start->toDateString(),
            'start_time' => $start->format('H:i'),
            'end_time' => $end->format('H:i'),
            'training_location_id' => $record->training_location_id,
            'note' => $record->note,
        ];
    }

    /** Trường form → cột (starts_at / ends_at theo UTC). */
    private static function toRecord(array $data): array
    {
        $date = CarbonImmutable::parse($data['date'], TrainingSession::TZ)->toDateString();
        $at = fn (string $time) => CarbonImmutable::parse($date . ' ' . substr($time, 0, 5), TrainingSession::TZ)->utc();

        return [
            'starts_at' => $at($data['start_time']),
            'ends_at' => $at($data['end_time']),
            'training_location_id' => $data['training_location_id'],
            'note' => $data['note'] ?? null,
        ];
    }
}

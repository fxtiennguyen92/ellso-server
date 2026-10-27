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
 * Tab "Lịch tổ chức": các ngày mở khoá — ngày, giờ, địa điểm (chọn từ sổ địa chỉ hoặc lưu mới), số người tối đa.
 * Form nhập ngày + giờ theo giờ Việt Nam; lưu starts_at / ends_at (UTC).
 */
class ManageTrainingSessions extends ManageRelatedRecords
{
    protected static string $resource = TrainingCourseResource::class;

    protected static string $relationship = 'sessions';

    protected static ?string $navigationLabel = 'Lịch tổ chức';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $title = 'Lịch tổ chức';

    protected static ?string $breadcrumb = 'Lịch tổ chức';

    protected static ?string $modelLabel = 'ngày tổ chức';

    public function getSubheading(): ?string
    {
        $course = $this->course();

        return $course->title . ' · ' . ($course->price ? Vnd::full($course->price) . ' / người' : 'Miễn phí')
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
                    ->helperText('Chọn địa điểm đã lưu, hoặc bấm + để lưu địa điểm mới (kèm nhãn) và dùng lại cho các ngày sau.')
                    ->createOptionForm(TrainingLocationForm::fields())
                    ->createOptionModalHeading('Lưu địa điểm mới')
                    ->createOptionUsing(fn (array $data) => TrainingLocation::create($data)->getKey())
                    ->columnSpan(['md' => 2]),
                TextInput::make('capacity')
                    ->label('Tối đa')
                    ->integer()
                    ->minValue(1)
                    ->suffix('người')
                    ->required(),
            ]),
            TextInput::make('note')
                ->label('Ghi chú cho ngày này')
                ->placeholder('VD: Mang theo laptop')
                ->maxLength(300)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at')
            ->modifyQueryUsing(fn ($query) => $query->with('location'))
            ->emptyStateHeading('Chưa có ngày tổ chức')
            ->emptyStateDescription('Thêm ngày mở khoá: ngày giờ, địa điểm và số người tối đa. Mỗi ngày có thể ở địa điểm khác nhau.')
            ->columns([
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
                TextColumn::make('capacity')
                    ->label('Tối đa')
                    ->formatStateUsing(fn (int $state) => $state . ' người')
                    ->alignCenter(),
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
                    ->label('Thêm ngày tổ chức')
                    ->icon(Heroicon::Plus)
                    ->color('primary')
                    ->modalHeading('Thêm ngày tổ chức')
                    ->modalWidth('3xl')
                    ->fillForm(fn () => $this->defaultsFromLastSession())
                    ->mutateDataUsing(fn (array $data) => self::toRecord($data))
                    ->createAnother(true),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->modalHeading(fn (TrainingSession $record) => 'Sửa ngày ' . $record->dateLabel())
                        ->modalWidth('3xl')
                        ->mutateRecordDataUsing(fn (array $data, TrainingSession $record) => [...$data, ...self::toForm($record)])
                        ->mutateDataUsing(fn (array $data) => self::toRecord($data)),
                    Action::make('repeat')
                        ->authorize('create', TrainingSession::class)
                        ->label('Thêm ngày tương tự')
                        ->icon(Heroicon::DocumentDuplicate)
                        ->modalHeading('Thêm ngày tương tự')
                        ->modalDescription(fn (TrainingSession $record) => 'Cùng giờ (' . $record->timeLabel() . '), địa điểm (' . $record->location?->label . ') và số người tối đa (' . $record->capacity . '). Chỉ cần chọn ngày.')
                        ->modalWidth('md')
                        ->schema([
                            DatePicker::make('date')->label('Ngày')->native(false)->displayFormat('d/m/Y')->required(),
                        ])
                        ->action(function (array $data, TrainingSession $record) {
                            $copy = $record->replicate(['cancelled_at']);
                            $copy->fill(self::toRecord([...self::toForm($record), 'date' => $data['date']]));
                            $copy->save();
                            Notification::make()->title('Đã thêm ngày ' . $copy->dateLabel())->success()->send();
                        }),
                    Action::make('cancel')
                        ->authorize('update')
                        ->label(fn (TrainingSession $record) => $record->isCancelled() ? 'Mở lại ngày này' : 'Huỷ ngày này')
                        ->icon(fn (TrainingSession $record) => $record->isCancelled() ? Heroicon::ArrowUturnLeft : Heroicon::XCircle)
                        ->color(fn (TrainingSession $record) => $record->isCancelled() ? 'gray' : 'danger')
                        ->requiresConfirmation()
                        ->modalDescription(fn (TrainingSession $record) => $record->isCancelled()
                            ? 'Ngày này sẽ hiện lại trên app.'
                            : 'Ngày này ẩn khỏi app nhưng vẫn giữ trong lịch để tra cứu.')
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

    /** Ngày mới lấy sẵn giờ, địa điểm, số người của ngày gần nhất. */
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
            'capacity' => $record->capacity,
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
            'capacity' => (int) $data['capacity'],
            'note' => $data['note'] ?? null,
        ];
    }
}

<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\UserRole;
use App\Filament\Resources\Classifications\ClassificationResource;
use App\Models\Classification;
use App\Models\Course;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use App\Support\MediaStorage;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make([
                    Section::make('Thông tin khóa học')
                        ->columns(2)
                        ->schema([
                            TextInput::make('title')
                                ->label('Tên khóa học')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),
                            TextInput::make('slug')
                                ->label('Slug')
                                ->helperText('Để trống để tự sinh từ tên.')
                                ->unique(ignoreRecord: true)
                                ->maxLength(255),
                            Select::make('category_id')
                                ->label('Danh mục')
                                ->relationship('category', 'name', fn (Builder $query) => $query->where('is_active', true))
                                ->searchable()
                                ->preload(),
                            Textarea::make('short_description')
                                ->label('Mô tả ngắn')
                                ->rows(2)
                                ->maxLength(500)
                                ->columnSpanFull(),
                            RichEditor::make('description')
                                ->label('Mô tả chi tiết')
                                ->fileAttachmentsDisk(MediaStorage::diskName())
                                ->fileAttachmentsDirectory('courses/descriptions')
                                ->columnSpanFull(),
                        ]),

                    Section::make('Dạng khóa học')
                        ->schema([
                            ToggleButtons::make('type')
                                ->hiddenLabel()
                                ->options(CourseType::class)
                                ->default(CourseType::Single)
                                ->inline()
                                ->required()
                                // Đã có nhiều lộ trình thì không chuyển về "khóa chung" được
                                ->disabled(fn (?Course $record) => $record && $record->learningPaths()->count() > 1)
                                ->helperText(fn (?Course $record) => $record && $record->learningPaths()->count() > 1
                                    ? 'Khóa đang có nhiều lộ trình. Xóa bớt còn 1 lộ trình nếu muốn chuyển về khóa học chung.'
                                    : 'Khóa học chung tự có 1 lộ trình mặc định. Đa lộ trình: chia lộ trình theo các phân loại bạn chọn bên dưới.')
                                ->live(),
                        ]),

                    Section::make('Phân loại lộ trình')
                        ->description('Khóa chia lộ trình theo những phân loại nào. Thứ tự ở đây là thứ tự câu hỏi trên app. Phân loại "Năng lực · …" không hỏi trên app mà lấy từ kết quả bài kiểm tra đầu vào.')
                        ->visible(fn (Get $get) => self::isMultiPath($get('type')))
                        ->schema([
                            // Dạng bảng: mỗi phân loại 1 hàng, nút kéo thả / xóa nằm cùng hàng
                            Repeater::make('courseClassifications')
                                ->hiddenLabel()
                                ->table([
                                    TableColumn::make('Phân loại (theo thứ tự câu hỏi)'),
                                ])
                                ->compact()
                                ->relationship()
                                ->orderColumn('sort_order')
                                ->addActionLabel('Thêm phân loại')
                                ->addAction(fn (Action $action) => $action->color('primary')->icon(Heroicon::Plus))
                                ->defaultItems(0)
                                ->schema([
                                    Select::make('classification_id')
                                        ->hiddenLabel()
                                        ->placeholder('Chọn phân loại')
                                        // Trong Repeater, $record là dòng CourseClassification → lấy khóa từ trang
                                        ->options(fn ($livewire) => Classification::query()
                                            ->availableFor(self::pageCourse($livewire)?->id)
                                            ->where('is_active', true)
                                            ->orderByRaw('course_id is not null') // dùng chung lên trước
                                            ->orderBy('sort_order')
                                            ->get()
                                            ->mapWithKeys(fn (Classification $c) => [
                                                $c->id => $c->isAbility() ? $c->displayName() : $c->name . ($c->isShared() ? '' : ' (riêng của khóa)'),
                                            ]))
                                        ->live()
                                        ->required()
                                        ->distinct()
                                        ->searchable(),
                                ]),
                            Text::make('Phân loại riêng của khóa (chỉ khóa này dùng) có thể tạo sau khi lưu khóa học.')
                                ->visible(fn (?Course $record) => $record === null),
                            Actions::make([
                                Action::make('createOwnClassification')
                                    ->label('Tạo phân loại riêng cho khóa này')
                                    ->icon(Heroicon::Plus)
                                    ->link()
                                    ->url(fn (?Course $record) => ClassificationResource::getUrl('create', ['course' => $record?->getKey()]))
                                    ->visible(fn (?Course $record) => $record !== null),
                            ]),
                        ]),
                ])->columnSpan(['lg' => 2]),

                Group::make([
                    Section::make('Xuất bản')
                        ->schema([
                            Select::make('status')
                                ->label('Trạng thái')
                                // Truyền thẳng enum để Filament cast state ↔ enum (closure trả về enum sẽ làm hỏng validation)
                                ->options(CourseStatus::class)
                                // Giảng viên chỉ được chọn Nháp / Chờ duyệt
                                ->disableOptionWhen(fn (string $value) => ! self::isAdmin()
                                    && ! array_key_exists($value, CourseStatus::instructorOptions()))
                                ->default(CourseStatus::Draft)
                                ->required()
                                ->disabled(fn (?Course $record) => ! self::isAdmin()
                                    && in_array($record?->status, [CourseStatus::Published, CourseStatus::Hidden], true))
                                ->helperText(fn () => self::isAdmin() ? null : 'Chọn "Chờ duyệt" để gửi admin duyệt xuất bản.'),
                            Select::make('instructor_id')
                                ->label('Giảng viên')
                                ->relationship('instructor', 'name', fn (Builder $query) => $query
                                    ->whereIn('role', [UserRole::Instructor->value, UserRole::Admin->value])
                                    ->where('is_active', true))
                                ->searchable()
                                ->preload()
                                ->required()
                                ->visible(fn () => self::isAdmin()),
                            TextInput::make('price')
                                ->label('Giá bán')
                                ->numeric()
                                ->minValue(0)
                                ->step(1000)
                                ->default(0)
                                ->suffix('đ')
                                ->helperText('0 = miễn phí')
                                ->required(),
                        ]),
                    Section::make('Bài kiểm tra đầu vào')
                        ->schema([
                            Toggle::make('requires_placement_test')
                                ->label('Bắt buộc làm trước khi học')
                                ->helperText(fn (Get $get) => $get('requires_placement_test')
                                    ? 'Học viên chưa làm bài kiểm tra đầu vào phải làm trước khi bắt đầu bài học đầu tiên.'
                                    : (self::usesAbility($get)
                                        ? 'Không bắt buộc: học viên chưa làm bài thì điều kiện Năng lực của lộ trình không khớp → thường vào lộ trình mặc định.'
                                        : 'Không bắt buộc: học viên học ngay, không cần làm bài kiểm tra.'))
                                ->live(),
                        ]),
                    Section::make('Ảnh bìa')
                        ->schema([
                            FileUpload::make('cover_image')
                                ->hiddenLabel()
                                ->image()
                                ->imageEditor()
                                ->disk(MediaStorage::diskName())
                                ->directory('courses/covers')
                                ->maxSize(4096),
                        ]),
                ])->columnSpan(['lg' => 1]),
            ]);
    }

    /** Khóa học đang mở trên trang Tạo / Sửa (null khi đang tạo mới). */
    private static function pageCourse(mixed $livewire): ?Course
    {
        $record = $livewire->record ?? null;

        return $record instanceof Course ? $record : null;
    }

    /** Khoá đang chọn phân loại Năng lực nào không (theo dữ liệu đang nhập). */
    private static function usesAbility(Get $get): bool
    {
        $ids = collect($get('courseClassifications') ?? [])->pluck('classification_id')->filter();

        return $ids->isNotEmpty() && self::isMultiPath($get('type'))
            && Classification::whereKey($ids->all())->where('type', Classification::TYPE_ABILITY)->exists();
    }

    private static function isMultiPath(mixed $type): bool
    {
        return ($type instanceof CourseType ? $type : CourseType::tryFrom((string) $type)) === CourseType::MultiPath;
    }

    private static function isAdmin(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }
}

<?php

namespace App\Filament\Resources\Classifications\Schemas;

use App\Models\Classification;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ClassificationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Phân loại')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên phân loại')
                            ->placeholder('VD: Nghề nghiệp, Mục tiêu học, Điểm yếu trong tư duy')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('question')
                            ->label('Câu hỏi trên app')
                            ->placeholder('VD: Bạn đang làm nghề gì?')
                            ->helperText('App hỏi câu này khi học viên bắt đầu khóa học.')
                            ->maxLength(255),

                        Select::make('course_id')
                            ->label('Phạm vi')
                            ->relationship('course', 'title', function (Builder $query) {
                                $user = auth()->user();

                                return $query->when($user?->isInstructor(), fn ($q) => $q->where('instructor_id', $user->id));
                            })
                            ->placeholder('Dùng chung cho mọi khóa học')
                            ->default(fn () => request()->integer('course') ?: null)
                            ->searchable()
                            ->preload()
                            // Giảng viên chỉ tạo phân loại riêng cho khóa của mình
                            ->required(fn () => ! auth()->user()?->isAdmin())
                            ->helperText(fn () => auth()->user()?->isAdmin()
                                ? 'Để trống = dùng chung. Chọn một khóa = phân loại riêng của khóa đó. Có thể đổi riêng → dùng chung bất cứ lúc nào.'
                                : 'Phân loại riêng chỉ dùng được trong khóa đã chọn.')
                            ->rules([
                                fn (?Classification $record): Closure => function (string $attribute, $value, Closure $fail) use ($record) {
                                    // Không chuyển phân loại đang được khóa khác dùng thành phân loại riêng
                                    if ($record && filled($value)
                                        && $record->courses()->where('courses.id', '!=', $value)->exists()) {
                                        $fail('Phân loại đang được khóa học khác sử dụng, không thể chuyển thành phân loại riêng.');
                                    }
                                },
                            ])
                            ->columnSpanFull(),

                        Textarea::make('description')
                            ->label('Ghi chú nội bộ')
                            ->rows(2)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Đang dùng')
                            ->default(true),
                    ]),

                Section::make('Các giá trị')
                    ->description('Học viên chọn một hoặc nhiều giá trị khi trả lời câu hỏi. Kéo thả để sắp xếp.')
                    ->schema([
                        Repeater::make('options')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Giá trị')->markAsRequired(),
                                TableColumn::make('Mô tả ngắn (hiện trên app)'),
                            ])
                            ->compact()
                            ->relationship()
                            ->orderColumn('sort_order')
                            ->minItems(1)
                            ->defaultItems(2)
                            ->addActionLabel('Thêm giá trị')
                            ->addAction(fn (Action $action) => $action->color('success')->icon(Heroicon::Plus))
                            ->schema([
                                TextInput::make('name')
                                    ->placeholder('VD: Marketing')
                                    ->required()
                                    ->distinct()
                                    ->maxLength(255),
                                TextInput::make('description')
                                    ->maxLength(255),
                            ]),
                    ]),
            ]);
    }
}
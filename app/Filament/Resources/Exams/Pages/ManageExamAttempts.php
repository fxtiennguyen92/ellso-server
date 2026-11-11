<?php

namespace App\Filament\Resources\Exams\Pages;

use App\Filament\Resources\Exams\ExamResource;
use App\Models\Exam;
use App\Models\ExamAttempt;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Tab "Bài làm" của đề thi: các lần nộp bài của học viên (điểm, đậu / trượt, xem từng câu). */
class ManageExamAttempts extends ManageRelatedRecords
{
    protected static string $resource = ExamResource::class;

    protected static string $relationship = 'attempts';

    protected static ?string $navigationLabel = 'Bài làm';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $title = 'Bài làm của học viên';

    protected static ?string $breadcrumb = 'Bài làm';

    public function getSubheading(): ?string
    {
        return $this->getOwnerRecord()->title . ' — mỗi lần nộp bài là một dòng; học viên làm lại không giới hạn.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->whereNotNull('submitted_at')->with(['user', 'course']))
            ->defaultSort('submitted_at', 'desc')
            ->emptyStateHeading('Chưa có bài làm')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Học viên')
                    ->description(fn (ExamAttempt $record) => $record->user?->email)
                    ->searchable(),
                TextColumn::make('course.title')->label('Khoá học')->placeholder('—')->wrap(),
                TextColumn::make('score')
                    ->label('Điểm')
                    ->alignCenter()
                    ->formatStateUsing(fn ($state, ExamAttempt $record) => rtrim(rtrim(number_format((float) $state, 1, ',', ''), '0'), ',') . ' / 100'
                        . ' (' . $record->correct_count . '/' . $record->total . ' câu)'),
                TextColumn::make('passed')
                    ->label('Kết quả')
                    ->badge()
                    ->formatStateUsing(fn (bool $state, ExamAttempt $record) => ($state ? 'Đậu' : 'Chưa đạt') . ($record->timed_out ? ' · hết giờ' : ''))
                    ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                TextColumn::make('submitted_at')
                    ->label('Nộp lúc')
                    ->dateTime('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->description(fn (ExamAttempt $record) => 'Làm trong ' . max(1, (int) ceil($record->started_at->diffInSeconds($record->submitted_at) / 60)) . ' phút'),
            ])
            ->filters([
                TernaryFilter::make('passed')->label('Kết quả')->trueLabel('Đậu')->falseLabel('Chưa đạt'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Xem bài')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->modalHeading(fn (ExamAttempt $record) => 'Bài làm của ' . ($record->user?->name ?? 'học viên'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (ExamAttempt $record) => new HtmlString('<ol style="display:flex;flex-direction:column;gap:10px;padding-left:20px;list-style:decimal">'
                        . collect($record->review ?? [])->map(fn ($r) => '<li><div style="font-size:14px">' . e($r['prompt']) . '</div>'
                            . '<div style="font-size:13px;margin-top:2px;color:' . ($r['correct'] ? 'var(--success-600)' : 'var(--danger-600)') . '">'
                            . ($r['correct'] ? '✓ ' : '✗ ') . e(implode(' · ', $r['chosen_labels']) ?: '(bỏ trống)') . '</div>'
                            . ($r['correct'] ? '' : '<div style="font-size:12px;opacity:.7">Đáp án đúng: ' . e(implode(' · ', $r['correct_labels'])) . '</div>')
                            . '</li>')->join('') . '</ol>')),
            ]);
    }
}

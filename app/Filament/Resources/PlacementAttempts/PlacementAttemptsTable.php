<?php

namespace App\Filament\Resources\PlacementAttempts;

use App\Models\PlacementAttempt;
use App\Models\PlacementTest;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Bảng bài làm kiểm tra đầu vào — dùng cho menu "Bài làm đầu vào" (mọi bài) và tab "Bài làm" của từng bài.
 * $newSince: bài làm nộp sau mốc này có chấm đỏ "Mới" (mốc đã xem trước đó của người đang xem).
 */
class PlacementAttemptsTable
{
    public static function configure(Table $table, ?string $newSince, bool $showTest): Table
    {
        $since = $newSince ? CarbonImmutable::parse($newSince) : null;
        $isNew = fn (PlacementAttempt $record) => $since === null || $record->created_at->gt($since);

        return $table
            ->defaultSort('completed_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name,email', 'test:id,title']))
            ->emptyStateHeading('Chưa có học viên làm bài')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Học viên')
                    ->formatStateUsing(fn (?string $state, PlacementAttempt $record) => new HtmlString(
                        ($isNew($record) ? self::dot() : '') . e($state ?? 'Học viên đã xoá')
                    ))
                    ->description(fn (PlacementAttempt $record) => $record->user?->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('test.title')
                    ->label('Bài kiểm tra')
                    ->visible($showTest)
                    ->color('gray')
                    ->wrap(),
                TextColumn::make('results')
                    ->label('Kết quả')
                    ->state(fn (PlacementAttempt $record) => self::resultChips($record))
                    ->html()
                    ->wrap(),
                TextColumn::make('completed_at')
                    ->label('Thời gian nộp')
                    ->dateTime('H:i d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('new')
                    ->label('Chỉ bài làm mới')
                    ->toggle()
                    ->query(fn ($query) => $since ? $query->where('created_at', '>', $since) : $query),
                SelectFilter::make('placement_test_id')
                    ->label('Bài kiểm tra')
                    ->options(fn () => PlacementTest::latest('id')->pluck('title', 'id'))
                    ->visible($showTest),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Xem bài làm')
                    ->icon(Heroicon::Eye)
                    ->iconButton()
                    ->tooltip('Xem bài làm')
                    ->modalHeading(fn (PlacementAttempt $record) => 'Bài làm của ' . ($record->user?->name ?? 'học viên'))
                    ->modalDescription(fn (PlacementAttempt $record) => ($record->test?->title ? $record->test->title . ' · ' : '')
                        . 'Nộp lúc ' . $record->completed_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (PlacementAttempt $record) => view('filament.placement-tests.attempt', [
                        'results' => $record->results ?? [],
                        'answers' => $record->answers ?? [],
                    ])),
                DeleteAction::make()->iconButton(),
            ]);
    }

    private static function dot(): string
    {
        return '<span title="Bài làm mới" style="display:inline-block;width:8px;height:8px;border-radius:999px;background:var(--danger-500);margin-right:8px;vertical-align:middle"></span>';
    }

    public static function resultChips(PlacementAttempt $record): HtmlString
    {
        return new HtmlString('<div style="display:flex;flex-wrap:wrap">' . collect($record->results ?? [])->map(function (array $r) {
            $top = ($r['rank'] ?? null) === 1;
            $style = $top
                ? 'background:color-mix(in oklab, var(--success-500) 14%, transparent);color:var(--success-700)'
                : 'background:color-mix(in oklab, var(--gray-500) 12%, transparent);color:var(--gray-700)';

            return '<span style="display:inline-block;padding:1px 8px;margin:2px 4px 2px 0;border-radius:999px;font-size:12px;' . $style . '">'
                . e($r['name']) . ': <b>' . e($r['level'] ?? '—') . '</b></span>';
        })->join('') . '</div>');
    }
}

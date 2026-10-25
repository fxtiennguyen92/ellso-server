<?php

namespace App\Filament\Resources\PlacementTests\Pages;

use App\Filament\Resources\PlacementTests\PlacementTestResource;
use App\Models\PlacementAttempt;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Tab "Bài làm": kết quả từng lần làm bài của học viên (bản chụp lúc nộp). */
class ManagePlacementAttempts extends ManageRelatedRecords
{
    protected static string $resource = PlacementTestResource::class;

    protected static string $relationship = 'attempts';

    protected static ?string $navigationLabel = 'Bài làm';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $title = 'Bài làm của học viên';

    protected static ?string $breadcrumb = 'Bài làm';

    protected static ?string $modelLabel = 'bài làm';

    public function getSubheading(): ?string
    {
        return $this->getOwnerRecord()->title . ' — học viên có thể làm lại; kết quả mới nhất được dùng để gợi ý.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('completed_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name,email'))
            ->emptyStateHeading('Chưa có học viên làm bài')
            ->columns([
                TextColumn::make('user.name')
                    ->label('Học viên')
                    ->description(fn (PlacementAttempt $record) => $record->user?->email)
                    ->searchable(['name', 'email']),
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
            ->recordActions([
                Action::make('view')
                    ->label('Xem bài làm')
                    ->icon(Heroicon::Eye)
                    ->iconButton()
                    ->tooltip('Xem bài làm')
                    ->modalHeading(fn (PlacementAttempt $record) => 'Bài làm của ' . ($record->user?->name ?? 'học viên'))
                    ->modalDescription(fn (PlacementAttempt $record) => 'Nộp lúc ' . $record->completed_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'))
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

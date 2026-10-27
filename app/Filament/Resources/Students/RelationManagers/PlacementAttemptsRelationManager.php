<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Filament\Resources\PlacementAttempts\PlacementAttemptsTable;
use App\Models\PlacementAttempt;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Học bạ › Bài kiểm tra đầu vào: các lần làm bài, kết quả năng lực, xem câu trả lời. */
class PlacementAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'placementAttempts';

    protected static ?string $title = 'Bài kiểm tra đầu vào';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedClipboardDocumentCheck;

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ($n = $ownerRecord->placementAttempts()->count()) ? (string) $n : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('test:id,title'))
            ->defaultSort('completed_at', 'desc')
            ->emptyStateHeading('Chưa làm bài kiểm tra đầu vào')
            ->columns([
                TextColumn::make('completed_at')->label('Thời gian')->dateTime('H:i d/m/Y', 'Asia/Ho_Chi_Minh')->sortable(),
                TextColumn::make('test.title')->label('Bài kiểm tra')->color('gray'),
                TextColumn::make('results')
                    ->label('Năng lực')
                    ->state(fn (PlacementAttempt $record) => PlacementAttemptsTable::resultChips($record))
                    ->html()
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Xem bài làm')
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->modalHeading(fn (PlacementAttempt $record) => 'Bài làm lúc ' . $record->completed_at->timezone('Asia/Ho_Chi_Minh')->format('H:i d/m/Y'))
                    ->modalWidth('3xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(fn (PlacementAttempt $record) => view('filament.placement-tests.attempt', [
                        'results' => $record->results ?? [],
                        'answers' => $record->answers ?? [],
                    ])),
            ]);
    }
}

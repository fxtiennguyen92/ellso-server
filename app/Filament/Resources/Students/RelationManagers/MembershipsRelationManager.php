<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Filament\Resources\Students\StudentResource;
use App\Models\StudentMembership;
use App\Support\Vnd;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Học bạ › Gói thành viên: các gói (mua / tặng / admin cấp), hiệu lực, số tiền. */
class MembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Gói thành viên';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedIdentification;

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('createdBy:id,name'))
            ->emptyStateHeading('Chưa có gói thành viên')
            ->columns([
                TextColumn::make('period')
                    ->label('Hiệu lực')
                    ->state(fn (StudentMembership $record) => $record->starts_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') . ' – ' . $record->ends_at->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y')),
                TextColumn::make('state')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (StudentMembership $record) => match (true) {
                        $record->isActive() => 'Đang dùng',
                        $record->starts_at->isFuture() => 'Nối tiếp',
                        default => 'Đã hết hạn',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Đang dùng' => 'success',
                        'Nối tiếp' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('source')->label('Nguồn')->badge(),
                TextColumn::make('price')->label('Số tiền')->formatStateUsing(fn (int $state) => $state ? Vnd::full($state) : 'Miễn phí'),
                TextColumn::make('note')
                    ->label('Ghi chú')
                    ->description(fn (StudentMembership $record) => $record->createdBy ? 'bởi ' . $record->createdBy->name : null)
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->headerActions(collect(StudentResource::accountActions())
                ->filter(fn ($action) => $action->getName() === 'grantMembership')
                ->map(fn ($action) => $action->record(fn () => $this->getOwnerRecord()))
                ->values()
                ->all());
    }
}

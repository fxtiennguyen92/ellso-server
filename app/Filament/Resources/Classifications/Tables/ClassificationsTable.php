<?php

namespace App\Filament\Resources\Classifications\Tables;

use App\Models\Classification;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClassificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Phân loại')
                    ->searchable()
                    ->description(fn (Classification $record) => $record->question),
                TextColumn::make('scope')
                    ->label('Phạm vi')
                    ->state(fn (Classification $record) => $record->course?->title ?? 'Dùng chung')
                    ->badge()
                    ->color(fn (Classification $record) => $record->isShared() ? 'success' : 'gray'),
                TextColumn::make('options.name')
                    ->label('Giá trị')
                    ->badge()
                    ->limitList(5),
                TextColumn::make('courses_count')->label('Số khóa dùng')->counts('courses'),
                IconColumn::make('is_active')->label('Đang dùng')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('shared')
                    ->label('Phạm vi')
                    ->trueLabel('Dùng chung')
                    ->falseLabel('Riêng của khóa')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNull('course_id'),
                        false: fn (Builder $query) => $query->whereNotNull('course_id'),
                    ),
            ])
            // Bấm vào hàng = sửa; các thao tác khác trong menu ⋮
            ->recordActions([
                ActionGroup::make([
                    DeleteAction::make()
                        ->modalDescription('Các lộ trình đang dùng phân loại này sẽ mất điều kiện tương ứng.'),
                ]),
            ]);
    }
}

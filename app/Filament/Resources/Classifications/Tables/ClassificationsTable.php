<?php

namespace App\Filament\Resources\Classifications\Tables;

use App\Filament\Resources\Classifications\ClassificationResource;
use App\Filament\Resources\PlacementTests\Pages\ManagePlacementDimensions;
use App\Models\Classification;
use App\Support\AbilityClassifications;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    ->weight('medium')
                    ->description(fn (Classification $record) => $record->isAbility()
                        ? 'Lấy tự động từ kết quả bài kiểm tra đầu vào'
                        : $record->question),
                TextColumn::make('type')
                    ->label('Loại')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === Classification::TYPE_ABILITY ? 'Năng lực' : 'Câu hỏi')
                    ->icon(fn (string $state) => $state === Classification::TYPE_ABILITY ? Heroicon::ClipboardDocumentCheck : Heroicon::ChatBubbleLeftRight)
                    ->color(fn (string $state) => $state === Classification::TYPE_ABILITY ? 'warning' : 'info')
                    ->tooltip(fn (string $state) => $state === Classification::TYPE_ABILITY
                        ? 'Giá trị = mức độ trong bài kiểm tra đầu vào; app không hỏi, lấy từ bài làm của học viên'
                        : 'App hỏi học viên câu hỏi này khi bắt đầu khoá'),
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
            ->recordUrl(function (Classification $record) {
                if (! $record->isAbility()) {
                    return auth()->user()?->can('update', $record) ? ClassificationResource::getUrl('edit', ['record' => $record]) : null;
                }
                $test = auth()->user()?->isAdmin() ? AbilityClassifications::testFor($record) : null;

                return $test ? ManagePlacementDimensions::getUrl(['record' => $test]) : null;
            })
            ->filters([
                SelectFilter::make('type')
                    ->label('Loại')
                    ->options([Classification::TYPE_QUESTION => 'Câu hỏi', Classification::TYPE_ABILITY => 'Năng lực']),
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

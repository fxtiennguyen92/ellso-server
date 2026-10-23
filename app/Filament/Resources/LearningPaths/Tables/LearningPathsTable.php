<?php

namespace App\Filament\Resources\LearningPaths\Tables;

use App\Filament\Resources\LearningPaths\LearningPathResource;
use App\Models\LearningPath;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Danh sách lộ trình của MỘT khóa học (trang Khóa học › Lộ trình học). */
class LearningPathsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('options.classification'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            // Bấm vào hàng = sửa thông tin & điều kiện
            ->recordUrl(fn (LearningPath $record) => LearningPathResource::urlFor('edit', $record))
            ->columns([
                TextColumn::make('name')->label('Lộ trình')->searchable(),
                TextColumn::make('conditions')
                    ->label('Áp dụng cho')
                    ->state(fn (LearningPath $record) => $record->conditionLabels() ?: ['Mọi học viên'])
                    ->badge()
                    ->color('gray')
                    ->listWithLineBreaks(),
                IconColumn::make('is_default')->label('Mặc định')->boolean(),
                TextColumn::make('chapters_count')->label('Chương')->counts('chapters'),
                TextColumn::make('path_lessons_count')->label('Bài học')->counts('pathLessons'),
            ])
            ->recordActions([
                Action::make('lessons')
                    ->label('Bài học')
                    ->icon(Heroicon::RectangleStack)
                    ->url(fn (LearningPath $record) => LearningPathResource::urlFor('build', $record)),
                ActionGroup::make([
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading('Chưa có lộ trình')
            ->emptyStateDescription('Khóa đa lộ trình: tạo một lộ trình cho mỗi nhóm học viên theo các phân loại của khóa.');
    }
}
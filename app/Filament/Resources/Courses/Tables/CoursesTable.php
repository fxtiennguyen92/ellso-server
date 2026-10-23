<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\UserRole;
use App\Filament\Resources\Courses\CourseResource;
use App\Models\Course;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        $isAdmin = (bool) auth()->user()?->isAdmin();

        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('cover_image')
                    ->label('')
                    ->disk('public')
                    ->imageHeight(40),
                TextColumn::make('title')
                    ->label('Khóa học')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Course $record) => $record->category?->name),
                TextColumn::make('instructor.name')
                    ->label('Giảng viên')
                    ->visible($isAdmin),
                TextColumn::make('type')->label('Dạng')->badge()->color('gray'),
                TextColumn::make('status')->label('Trạng thái')->badge(),
                TextColumn::make('price')
                    ->label('Giá')
                    ->formatStateUsing(fn (int $state) => $state === 0 ? 'Miễn phí' : number_format($state, 0, ',', '.') . ' đ')
                    ->sortable(),
                TextColumn::make('lessons_total')
                    ->label('Bài học')
                    ->state(fn (Course $record) => $record->lessonsQuery()->count()),
                TextColumn::make('learning_paths_count')->label('Lộ trình')->counts('learningPaths'),
                TextColumn::make('updated_at')->label('Cập nhật')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(CourseStatus::class),
                SelectFilter::make('type')->label('Dạng')->options(CourseType::class),
                SelectFilter::make('category')->label('Danh mục')->relationship('category', 'name'),
                SelectFilter::make('instructor')
                    ->label('Giảng viên')
                    ->relationship('instructor', 'name', fn (Builder $query) => $query->where('role', UserRole::Instructor->value))
                    ->visible($isAdmin),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Bấm vào hàng = sửa khóa học; nút trên hàng mở danh sách lộ trình của khóa
                Action::make('learningPaths')
                    ->label('Lộ trình học')
                    ->icon(Heroicon::Map)
                    ->url(fn (Course $record) => CourseResource::getUrl('learning-paths', ['record' => $record]))
                    ->hidden(fn (Course $record) => $record->trashed()),
                ActionGroup::make([
                    // Admin duyệt & xuất bản
                    Action::make('publish')
                        ->label('Duyệt & xuất bản')
                        ->icon(Heroicon::CheckBadge)
                        ->color('success')
                        ->requiresConfirmation()
                        ->authorize('publish')
                        ->visible(fn (Course $record) => $record->status !== CourseStatus::Published && ! $record->trashed())
                        ->action(function (Course $record) {
                            $record->update(['status' => CourseStatus::Published]);
                            Notification::make()->title('Đã xuất bản khóa học')->success()->send();
                        }),
                    // Admin ẩn khóa học
                    Action::make('hide')
                        ->label('Ẩn khóa học')
                        ->icon(Heroicon::EyeSlash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->authorize('publish')
                        ->visible(fn (Course $record) => $record->status === CourseStatus::Published)
                        ->action(fn (Course $record) => $record->update(['status' => CourseStatus::Hidden])),
                    // Giảng viên gửi duyệt
                    Action::make('submit')
                        ->label('Gửi duyệt')
                        ->icon(Heroicon::PaperAirplane)
                        ->requiresConfirmation()
                        ->authorize('update')
                        ->visible(fn (Course $record) => ! auth()->user()->isAdmin() && $record->status === CourseStatus::Draft)
                        ->action(function (Course $record) {
                            $record->update(['status' => CourseStatus::Pending]);
                            Notification::make()->title('Đã gửi admin duyệt')->success()->send();
                        }),
                    DeleteAction::make(),
                    RestoreAction::make(),
                    ForceDeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
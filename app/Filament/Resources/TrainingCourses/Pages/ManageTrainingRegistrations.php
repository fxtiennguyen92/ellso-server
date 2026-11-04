<?php

namespace App\Filament\Resources\TrainingCourses\Pages;

use App\Enums\RegistrationSource;
use App\Exceptions\RegistrationRefused;
use App\Filament\Resources\TrainingCourses\TrainingCourseResource;
use App\Filament\Support\RegistrantFields;
use App\Models\TrainingCourse;
use App\Models\TrainingRegistration;
use App\Models\User;
use App\Services\EventRegistration;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Tab "Người đăng ký": học viên đăng ký cả chương trình (tự đăng ký trên app hoặc admin thêm — VD đã chuyển khoản).
 * Admin thêm / huỷ; giảng viên xem danh sách của chương trình mình hướng dẫn.
 */
class ManageTrainingRegistrations extends ManageRelatedRecords
{
    protected static string $resource = TrainingCourseResource::class;

    protected static string $relationship = 'registrations';

    protected static ?string $navigationLabel = 'Người đăng ký';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $title = 'Người đăng ký';

    protected static ?string $breadcrumb = 'Người đăng ký';

    protected static ?string $modelLabel = 'người đăng ký';

    public function getSubheading(): ?string
    {
        $course = $this->course();
        $count = $course->registrations()->count();

        return $course->title . ' · ' . $count . ' / ' . $course->capacity . ' học viên'
            . ($count >= $course->capacity ? ' (đã đủ)' : ' · còn ' . $course->seatsLeft($count) . ' chỗ');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'createdBy:id,name']))
            ->emptyStateHeading('Chưa có ai đăng ký')
            ->emptyStateDescription('Học viên có gói thành viên tự đăng ký trên app (chương trình miễn phí), hoặc admin thêm tay — VD học viên đã chuyển khoản phí tham gia.')
            ->columns(RegistrantFields::columns())
            ->filters([
                SelectFilter::make('source')->label('Nguồn')->options(RegistrationSource::class),
            ])
            ->headerActions([
                Action::make('addRegistrant')
                    ->label('Thêm người đăng ký')
                    ->icon(Heroicon::UserPlus)
                    ->color('primary')
                    ->authorize('create', TrainingRegistration::class)
                    ->modalHeading('Thêm người đăng ký')
                    ->modalDescription('Học viên được tính vào số chỗ của chương trình. Không cần thanh toán trên app (VD: đã chuyển khoản).')
                    ->modalWidth('lg')
                    ->schema(RegistrantFields::form())
                    ->action(function (array $data, Action $action) {
                        try {
                            app(EventRegistration::class)->registerTraining(
                                User::findOrFail($data['user_id']), $this->course(), RegistrationSource::Admin, auth()->user(), $data['note'] ?? null,
                            );
                        } catch (RegistrationRefused $e) {
                            Notification::make()->title($e->reason === 'membership_required'
                                ? 'Học viên chưa có gói thành viên còn hạn — cấp gói ở Học bạ trước.'
                                : ($e->reason === 'full' ? 'Chương trình đã đủ số học viên tối đa — tăng số tối đa ở tab Thông tin nếu cần.' : $e->getMessage()))
                                ->danger()->send();
                            $action->halt();

                            return;
                        }
                        Notification::make()->title('Đã thêm người đăng ký')->success()->send();
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label('Huỷ đăng ký')
                    ->modalHeading('Huỷ đăng ký')
                    ->modalDescription(fn (TrainingRegistration $record) => 'Huỷ đăng ký của ' . $record->user?->name . ' — chỗ này được trả lại cho chương trình.'),
            ]);
    }

    private function course(): TrainingCourse
    {
        /** @var TrainingCourse */
        return $this->getOwnerRecord();
    }
}

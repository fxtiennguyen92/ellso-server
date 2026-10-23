<?php

namespace App\Filament\Resources\EmailTemplates\Pages;

use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Models\EmailTemplate;
use App\Support\EmailTemplatePreview;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EditEmailTemplate extends EditRecord
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Xem trước nội dung đang gõ (chưa cần lưu)
            Action::make('preview')
                ->label('Xem trước')
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->modalHeading(fn () => 'Xem trước: ' . $this->previewTemplate()->text($this->previewTemplate()->subject, $this->previewTemplate()->sampleValues()))
                ->modalWidth(Width::FourExtraLarge)
                ->modalContent(fn () => view('filament.email-templates.preview', [
                    'html' => EmailTemplatePreview::mail($this->previewTemplate())->render(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng'),

            Action::make('sendTest')
                ->label('Gửi thử cho tôi')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Gửi email mẫu (nội dung đang hiển thị, kể cả phần chưa lưu) tới ' . auth()->user()->email . '.')
                ->action(function () {
                    try {
                        Mail::to(auth()->user()->email)->send(
                            EmailTemplatePreview::mail($this->previewTemplate(), auth()->user()),
                        );

                        Notification::make()->title('Đã gửi thử tới ' . auth()->user()->email)->success()->send();
                    } catch (Throwable $e) {
                        Log::error('Gửi thử mẫu email thất bại', ['error' => $e->getMessage()]);

                        Notification::make()
                            ->title('Không gửi được email')
                            ->body('Kiểm tra cấu hình mail (MAIL_*) rồi thử lại.')
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by_id'] = auth()->id();

        return $data;
    }

    private function previewTemplate(): EmailTemplate
    {
        /** @var EmailTemplate $record */
        $record = $this->getRecord();

        return EmailTemplatePreview::withFormData($record, $this->form->getRawState());
    }
}

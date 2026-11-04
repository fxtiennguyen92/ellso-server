<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Enums\DocumentType;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Support\BookPreview;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditDocument extends EditRecord
{
    use SavesDocument;

    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('regeneratePreview')
                ->label('Tạo lại bản đọc thử')
                ->icon(Heroicon::ArrowPath)
                ->color('gray')
                ->visible(function () {
                    /** @var Document $doc */
                    $doc = $this->getRecord();

                    return $doc->type === DocumentType::Book && $doc->preview_enabled && $doc->file_path;
                })
                ->action(function () {
                    /** @var Document $doc */
                    $doc = $this->getRecord();
                    BookPreview::refresh($doc);
                    $doc->refresh();
                    $this->refreshFormData(['preview_status']);
                    Notification::make()->title('Đang tạo lại bản đọc thử')->success()->send();
                }),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->normalizeData($data);
    }

    protected function afterSave(): void
    {
        $this->afterDocumentSaved($this->getRecord());
    }
}

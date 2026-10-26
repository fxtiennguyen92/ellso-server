<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDocument extends CreateRecord
{
    use SavesDocument;

    protected static string $resource = DocumentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$this->normalizeData($data), 'created_by_id' => auth()->id()];
    }

    protected function afterCreate(): void
    {
        $this->afterDocumentSaved($this->getRecord());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}

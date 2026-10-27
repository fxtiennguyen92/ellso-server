<?php

namespace App\Filament\Resources\DocumentTopics\Pages;

use App\Filament\Resources\DocumentTopics\DocumentTopicResource;
use App\Models\DocumentTopic;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageDocumentTopics extends ManageRecords
{
    protected static string $resource = DocumentTopicResource::class;

    public function getSubheading(): ?string
    {
        return 'Chủ đề chỉ dùng để lọc tài liệu trên app, không hiện trên từng tài liệu. Kéo thả để đổi thứ tự.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Thêm chủ đề')
                ->icon(Heroicon::Plus)
                ->color('primary')
                ->mutateDataUsing(fn (array $data) => [...$data, 'sort_order' => (int) DocumentTopic::max('sort_order') + 1]),
        ];
    }
}

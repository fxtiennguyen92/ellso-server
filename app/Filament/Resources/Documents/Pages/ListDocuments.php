<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Enums\DocumentType;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    public function getSubheading(): ?string
    {
        return 'Video, PDF và sách. Học viên xem được khi đủ một điều kiện: miễn phí, gói thành viên, khoá học / bài học đã mở, hoặc đã mua (sách).';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Thêm tài liệu')->icon(Heroicon::Plus)->color('primary'),
        ];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('Tất cả')];
        foreach (DocumentType::cases() as $type) {
            $tabs[$type->value] = Tab::make($type->getLabel())
                ->icon($type->getIcon())
                ->badge(Document::where('type', $type)->count() ?: null)
                ->modifyQueryUsing(fn ($query) => $query->where('type', $type));
        }

        return $tabs;
    }
}

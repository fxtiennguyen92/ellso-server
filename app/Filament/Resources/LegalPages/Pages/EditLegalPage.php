<?php

namespace App\Filament\Resources\LegalPages\Pages;

use App\Filament\Resources\LegalPages\LegalPageResource;
use App\Models\LegalPage;
use App\Support\LegalMarkdown;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class EditLegalPage extends EditRecord
{
    protected static string $resource = LegalPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Xem trước đúng như app hiển thị (đã thay biến), kể cả phần chưa lưu
            Action::make('preview')
                ->label('Xem trước')
                ->icon(Heroicon::Eye)
                ->color('gray')
                ->modalHeading(fn () => $this->data['title'] ?? 'Xem trước')
                ->modalWidth(Width::TwoExtraLarge)
                ->modalContent(fn () => new HtmlString(self::previewHtml((string) ($this->data['content'] ?? ''))))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Đóng'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_by_id'] = auth()->id();

        return $data;
    }

    private static function previewHtml(string $content): string
    {
        $page = new LegalPage(['content' => $content]);
        $html = '';
        foreach (LegalMarkdown::toBlocks($page->renderedContent()) as $b) {
            $html .= match ($b['type']) {
                'header' => $b['data']['level'] <= 2
                    ? '<h3 style="font-size:17px;font-weight:700;margin:20px 0 6px">'.$b['data']['text'].'</h3>'
                    : '<h4 style="font-size:15px;font-weight:700;margin:14px 0 4px">'.$b['data']['text'].'</h4>',
                'list' => '<'.($b['data']['style'] === 'ordered' ? 'ol' : 'ul').' style="margin:6px 0 10px 20px;list-style:'.($b['data']['style'] === 'ordered' ? 'decimal' : 'disc').'">'
                    .collect($b['data']['items'])->map(fn ($i) => '<li style="margin:3px 0">'.$i.'</li>')->join('')
                    .'</'.($b['data']['style'] === 'ordered' ? 'ol' : 'ul').'>',
                default => '<p style="margin:8px 0;line-height:1.6">'.$b['data']['text'].'</p>',
            };
        }

        return '<div style="font-size:14px">'.$html.'</div>';
    }
}

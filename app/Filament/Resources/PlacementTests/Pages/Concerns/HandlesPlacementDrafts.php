<?php

namespace App\Filament\Resources\PlacementTests\Pages\Concerns;

use App\Filament\Resources\PlacementTests\Pages\ManagePlacementQuestions;
use App\Models\PlacementTest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Bản nháp (Nhân bản › Bản nháp để sửa đề) trên các tab của một bài kiểm tra:
 * - Đang xem bài đang dùng có bản nháp → nút "Xem bản nháp".
 * - Đang xem bản nháp → dòng nhắc trên tiêu đề + nút "Dùng bản nháp này" và "Xem bản đang dùng".
 */
trait HandlesPlacementDrafts
{
    protected function placementRecord(): PlacementTest
    {
        $record = method_exists($this, 'getOwnerRecord') ? $this->getOwnerRecord() : $this->getRecord();

        /** @var PlacementTest $record */
        return $record;
    }

    /** Tiền tố cho subheading khi đang xem bản nháp. */
    protected function draftNotice(): string
    {
        $test = $this->placementRecord();
        $active = $test->is_active ? null : PlacementTest::activeFor($test->key);

        return $active
            ? 'Bản nháp của "' . $active->title . '" — học viên chưa thấy. Sửa xong bấm "Dùng bản nháp này" để thay bản đang dùng. · '
            : '';
    }

    /** @return list<Action> */
    protected function draftActions(): array
    {
        return [
            Action::make('openDraft')
                ->label('Xem bản nháp')
                ->icon(Heroicon::PencilSquare)
                ->color('warning')
                ->visible(fn () => $this->placementRecord()->drafts()->isNotEmpty())
                ->url(fn () => ManagePlacementQuestions::getUrl(['record' => $this->placementRecord()->drafts()->first()])),
            Action::make('openActive')
                ->label('Xem bản đang dùng')
                ->icon(Heroicon::Eye)
                ->color('gray')
                ->visible(fn () => $this->placementRecord()->isDraft())
                ->url(fn () => ManagePlacementQuestions::getUrl(['record' => PlacementTest::activeFor($this->placementRecord()->key)])),
            Action::make('publishDraft')
                ->label('Dùng bản nháp này')
                ->icon(Heroicon::CheckBadge)
                ->color('success')
                ->visible(fn () => $this->placementRecord()->isDraft())
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Bản nháp này sẽ thay "' . PlacementTest::activeFor($this->placementRecord()->key)?->title
                    . '" đang dùng (bản đó được tắt, vẫn giữ để xem lại). Học viên đã làm bản cũ vẫn được tính là đã làm.')
                ->action(function () {
                    $test = $this->placementRecord();
                    $test->update(['is_active' => true]);
                    Notification::make()->title('Đang dùng "' . $test->title . '"')->success()->send();
                    $this->redirect(ManagePlacementQuestions::getUrl(['record' => $test]));
                }),
        ];
    }
}

<?php

namespace App\Filament\Resources\PlacementTests\Pages;

use App\Filament\Resources\PlacementAttempts\PlacementAttemptResource;
use App\Filament\Resources\PlacementAttempts\PlacementAttemptsTable;
use App\Filament\Resources\PlacementTests\PlacementTestResource;
use App\Support\SeenMarker;
use BackedEnum;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Tab "Bài làm": kết quả từng lần làm bài của học viên (bản chụp lúc nộp). */
class ManagePlacementAttempts extends ManageRelatedRecords
{
    protected static string $resource = PlacementTestResource::class;

    protected static string $relationship = 'attempts';

    protected static ?string $navigationLabel = 'Bài làm';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $title = 'Bài làm của học viên';

    protected static ?string $breadcrumb = 'Bài làm';

    protected static ?string $modelLabel = 'bài làm';

    public function getSubheading(): ?string
    {
        return $this->getOwnerRecord()->title . ' — học viên có thể làm lại; kết quả mới nhất được dùng để gợi ý.';
    }

    public static function getNavigationBadge(): ?string
    {
        return PlacementAttemptResource::getNavigationBadge();
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'danger';
    }

    /** Mốc đã xem trước khi mở tab (mở tab cũng tính là đã xem bài làm mới). */
    public ?string $newSince = null;

    public function mount(int | string $record): void
    {
        parent::mount($record);
        $this->newSince = SeenMarker::touch(auth()->user(), SeenMarker::PLACEMENT_ATTEMPTS)?->toIso8601String();
    }

    public function table(Table $table): Table
    {
        return PlacementAttemptsTable::configure($table, $this->newSince, showTest: false);
    }
}

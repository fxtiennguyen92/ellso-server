<?php

namespace App\Filament\Resources\PlacementAttempts\Pages;

use App\Filament\Resources\PlacementAttempts\PlacementAttemptResource;
use App\Filament\Resources\PlacementAttempts\PlacementAttemptsTable;
use App\Support\SeenMarker;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;

class ListPlacementAttempts extends ListRecords
{
    protected static string $resource = PlacementAttemptResource::class;

    /** Mốc đã xem trước khi mở trang (giữ suốt phiên xem để chấm đỏ không biến mất khi lọc / phân trang). */
    public ?string $newSince = null;

    public function mount(): void
    {
        parent::mount();
        $this->newSince = SeenMarker::touch(auth()->user(), SeenMarker::PLACEMENT_ATTEMPTS)?->toIso8601String();
    }

    public function getSubheading(): ?string
    {
        return 'Bài làm của học viên ở mọi bài kiểm tra đầu vào, mới nhất trước. Chấm đỏ = bài làm mới kể từ lần bạn xem trước.';
    }

    public function table(Table $table): Table
    {
        return PlacementAttemptsTable::configure($table, $this->newSince, showTest: true);
    }
}

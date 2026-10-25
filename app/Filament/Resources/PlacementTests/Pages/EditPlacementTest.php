<?php

namespace App\Filament\Resources\PlacementTests\Pages;

use App\Filament\Resources\PlacementTests\Pages\Concerns\SimulatesPlacementTest;
use App\Filament\Resources\PlacementTests\PlacementTestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPlacementTest extends EditRecord
{
    use SimulatesPlacementTest;

    protected static string $resource = PlacementTestResource::class;

    protected static ?string $navigationLabel = 'Thông tin';

    protected static string | \BackedEnum | null $navigationIcon = Heroicon::OutlinedInformationCircle;

    protected static ?string $title = 'Thông tin';

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->simulateAction(),
            DeleteAction::make(),
        ];
    }
}

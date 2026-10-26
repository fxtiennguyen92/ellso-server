<?php

namespace App\Filament\Resources\Students\RelationManagers;

use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentPurchase;
use App\Support\Vnd;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Học bạ › Sách đã mua: sách bán riêng học viên đã mua; admin cấp sách (VD: mua ngoài, tặng). */
class BookPurchasesRelationManager extends RelationManager
{
    protected static string $relationship = 'documentPurchases';

    protected static ?string $title = 'Sách đã mua';

    protected static string | \BackedEnum | null $icon = Heroicon::OutlinedBookOpen;

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return ($n = $ownerRecord->documentPurchases()->count()) ? (string) $n : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('purchased_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['document', 'grantedBy:id,name']))
            ->emptyStateHeading('Chưa mua sách nào')
            ->columns([
                TextColumn::make('document.title')->label('Sách')->weight('medium')->description(fn (DocumentPurchase $r) => $r->document?->author),
                TextColumn::make('price')->label('Số tiền')->formatStateUsing(fn (int $state) => $state ? Vnd::full($state) : 'Tặng'),
                TextColumn::make('source')->label('Nguồn')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'admin' ? 'Admin cấp' : 'Mua online')
                    ->color(fn (string $state) => $state === 'admin' ? 'gray' : 'success'),
                TextColumn::make('purchased_at')->label('Ngày')->dateTime('d/m/Y', 'Asia/Ho_Chi_Minh')
                    ->description(fn (DocumentPurchase $r) => $r->grantedBy ? 'bởi ' . $r->grantedBy->name : null),
                TextColumn::make('note')->label('Ghi chú')->placeholder('—')->wrap(),
            ])
            ->headerActions([
                Action::make('grantBook')
                    ->label('Cấp sách')
                    ->icon(Heroicon::Plus)
                    ->color('primary')
                    ->modalWidth('lg')
                    ->schema([
                        Select::make('document_id')
                            ->label('Sách')
                            ->options(fn () => Document::query()
                                ->where('type', DocumentType::Book)
                                ->whereNotNull('price')
                                ->whereNotIn('id', $this->getOwnerRecord()->documentPurchases()->pluck('document_id'))
                                ->orderBy('title')
                                ->get()
                                ->mapWithKeys(fn (Document $d) => [$d->id => $d->title . ' · ' . Vnd::full($d->price)]))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('price', Document::find($state)?->price ?? 0)),
                        TextInput::make('price')->label('Số tiền học viên đã trả')->numeric()->integer()->minValue(0)->suffix('₫')->default(0)->helperText('0 nếu tặng.'),
                        Textarea::make('note')->label('Ghi chú')->rows(2)->maxLength(300),
                    ])
                    ->action(function (array $data) {
                        DocumentPurchase::create([
                            'user_id' => $this->getOwnerRecord()->id,
                            'document_id' => $data['document_id'],
                            'price' => (int) ($data['price'] ?? 0),
                            'source' => 'admin',
                            'granted_by_id' => auth()->id(),
                            'note' => $data['note'] ?? null,
                            'purchased_at' => now(),
                        ]);
                        Notification::make()->title('Đã cấp sách')->success()->send();
                    }),
            ])
            ->recordActions([
                DeleteAction::make()->label('Thu hồi')->modalDescription('Học viên không xem được sách này nữa (trừ khi có điều kiện xem khác).'),
            ]);
    }
}

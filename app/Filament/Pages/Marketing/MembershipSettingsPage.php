<?php

namespace App\Filament\Pages\Marketing;

use App\Filament\Resources\MembershipCampaigns\MembershipCampaignResource;
use App\Models\Course;
use App\Models\MembershipBundleItem;
use App\Models\MembershipCampaign;
use App\Models\StudentMembership;
use App\Models\SystemSetting;
use App\Support\Vnd;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Marketing › Gói thành viên: giá gói (luôn 1 năm) và sản phẩm mua kèm được tặng gói.
 * Chiến dịch tặng gói khi đăng ký: Marketing › Chiến dịch tặng gói.
 */
class MembershipSettingsPage extends Page
{
    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string | UnitEnum | null $navigationGroup = 'Marketing';

    protected static ?string $navigationLabel = 'Gói thành viên';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Gói thành viên';

    protected static ?string $slug = 'membership';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function mount(): void
    {
        $this->form->fill([
            'price' => SystemSetting::studentMembershipPrice(),
            'courses' => MembershipBundleItem::idsOf('course'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Giá gói')
                    ->description('Gói thành viên luôn có thời hạn 1 năm: xem tài liệu và tham gia các khoá huấn luyện. Đang có gói còn hạn mà mua / được tặng thêm thì cộng nối 1 năm.')
                    ->schema([
                        TextInput::make('price')
                            ->label('Giá gói 1 năm')
                            ->suffix('₫')
                            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
                            ->stripCharacters('.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->helperText('Để trống nếu chưa mở bán.')
                            ->maxWidth('sm'),
                    ]),
                Section::make('Mua kèm được tặng gói')
                    ->description('Học viên mua một trong các sản phẩm dưới đây sẽ được tặng thêm gói thành viên 1 năm.')
                    ->schema([
                        Select::make('courses')
                            ->label('Khoá học')
                            ->placeholder('Chọn khoá học…')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->options(fn () => Course::query()->orderBy('title')->pluck('title', 'id')->all()),
                        Text::make('Tài liệu (sách, PDF, video): thêm được khi module tài liệu có trên server.'),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make(fn () => $this->summary()),
            Form::make([EmbeddedSchema::make('form')])
                ->id('membership-settings')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Lưu')->submit('save'),
                        Action::make('campaigns')
                            ->label('Chiến dịch tặng gói')
                            ->color('gray')
                            ->icon(Heroicon::Gift)
                            ->url(MembershipCampaignResource::getUrl()),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        DB::transaction(function () use ($data) {
            SystemSetting::query()
                ->where('key', SystemSetting::STUDENT_MEMBERSHIP_PRICE)
                ->first()
                ?->update(['value' => filled($data['price'] ?? null) ? (string) (int) $data['price'] : null]);

            MembershipBundleItem::sync('course', $data['courses'] ?? []);
        });

        Notification::make()->title('Đã lưu thiết lập gói thành viên')->success()->send();
    }

    private function summary(): string
    {
        $active = StudentMembership::query()->active()->distinct()->count('user_id');
        $running = MembershipCampaign::query()->runningAt()->count();
        $price = SystemSetting::studentMembershipPrice();

        return 'Giá hiện tại: ' . ($price !== null ? Vnd::full($price) : 'chưa đặt')
            . ' · ' . number_format($active, 0, ',', '.') . ' học viên đang có gói còn hạn'
            . ' · ' . $running . ' chiến dịch tặng gói đang chạy';
    }
}

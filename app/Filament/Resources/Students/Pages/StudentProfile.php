<?php

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\PlacementAttempts\PlacementAttemptsTable;
use App\Filament\Resources\Students\EnrollCourseAction;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Students\Widgets\StudentOverview;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Học bạ: trang tổng quan một học viên.
 * Trên: thẻ số liệu + thông tin tài khoản + năng lực (bài kiểm tra đầu vào).
 * Dưới (tab): Khoá học · Bài kiểm tra đầu vào · Gói thành viên · Lượt mở app · Nhật ký.
 */
class StudentProfile extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    protected static ?string $breadcrumb = 'Học bạ';

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedAcademicCap;

    private const TZ = 'Asia/Ho_Chi_Minh';

    public function getTitle(): string | Htmlable
    {
        return 'Học bạ · ' . $this->student()->name;
    }

    public function getSubheading(): string | Htmlable | null
    {
        $u = $this->student();

        return implode(' · ', array_filter([$u->email, $u->phone, $u->is_active ? null : 'Đang bị khoá']));
    }

    protected function getHeaderActions(): array
    {
        return [
            EnrollCourseAction::make(fn () => $this->student()),
            EditAction::make()
                ->label('Sửa thông tin')
                ->color('gray')
                ->icon(Heroicon::PencilSquare)
                ->schema(fn (Schema $schema) => StudentResource::form($schema)),
            ActionGroup::make([
                ...StudentResource::accountActions(),
                Action::make('logs')
                    ->label('Toàn bộ nhật ký')
                    ->icon(Heroicon::ClipboardDocumentList)
                    ->url(fn () => StudentResource::getUrl('logs', ['record' => $this->student()])),
            ])
                ->label('Thao tác')
                ->icon(Heroicon::EllipsisVertical)
                ->button()
                ->color('gray'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [StudentOverview::class];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Section::make('Tài khoản')
                    ->columnSpan(['lg' => 2])
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('created_at')->label('Ngày đăng ký')->dateTime('d/m/Y', self::TZ),
                        TextEntry::make('activation')
                            ->label('Kích hoạt')
                            ->badge()
                            ->state(fn (User $record) => match (true) {
                                $record->hasActivatedPassword() => 'Đã kích hoạt',
                                $record->wasInvited() => 'Đã mời, chưa đăng nhập',
                                default => 'Chưa đăng nhập',
                            })
                            ->color(fn (User $record) => $record->hasActivatedPassword() ? 'success' : 'warning'),
                        TextEntry::make('last_login_at')->label('Đăng nhập cuối')->dateTime('H:i d/m/Y', self::TZ)->placeholder('Chưa đăng nhập'),
                        TextEntry::make('referral_code')->label('Mã giới thiệu')->fontFamily('mono')->copyable(),
                        TextEntry::make('referrer.name')->label('Người giới thiệu')->placeholder('Không có'),
                        TextEntry::make('referrals_count')->label('Đã giới thiệu')->state(fn (User $record) => $record->referrals()->count() . ' người'),
                        TextEntry::make('last_device')
                            ->label('Thiết bị gần nhất')
                            ->state(fn (User $record) => ($s = $record->appSessions()->latest('last_seen_at')->first())
                                ? trim(($s->device_name ?: \App\Models\AppSession::platformLabel($s->platform)) . ' · ' . \App\Models\AppSession::platformLabel($s->platform) . ' ' . $s->os_version . ($s->app_version ? ' · app ' . $s->app_version : ''))
                                : null)
                            ->placeholder('Chưa mở app')
                            ->columnSpan(['md' => 2]),
                        TextEntry::make('classification_answers')
                            ->label('Câu trả lời phân loại gần nhất')
                            ->state(fn (User $record) => $record->classificationOptions()->with('classification')->get()
                                ->map(fn ($o) => $o->classification->name . ': ' . $o->name)->join(' · ') ?: null)
                            ->placeholder('Chưa có'),
                    ]),
                Section::make('Năng lực')
                    ->description('Theo bài kiểm tra đầu vào mới nhất')
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Text::make(fn (User $record) => $this->abilityHtml($record)),
                    ]),
            ]),
        ]);
    }

    private function abilityHtml(User $user): HtmlString
    {
        $attempt = $user->placementAttempts()->latest('completed_at')->latest('id')->first();
        if (! $attempt) {
            return new HtmlString('<span style="color:var(--gray-500)">Chưa làm bài kiểm tra đầu vào.</span>');
        }

        return new HtmlString(
            PlacementAttemptsTable::resultChips($attempt)->toHtml()
            . '<div style="margin-top:8px;font-size:12px;color:var(--gray-500)">Làm lúc ' . $attempt->completed_at->timezone(self::TZ)->format('H:i d/m/Y')
            . ($user->placementAttempts()->count() > 1 ? ' · ' . $user->placementAttempts()->count() . ' lần làm' : '') . '</div>'
        );
    }

    private function student(): User
    {
        /** @var User */
        return $this->getRecord();
    }
}

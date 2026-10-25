<?php

namespace Tests\Feature\Cms;

use App\Enums\UserLogEvent;
use App\Enums\UserRole;
use App\Filament\Resources\SystemSettings\Pages\ManageSystemSettings;
use App\Filament\Resources\Users\Pages\ManageUserLogs;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Mail\TempPasswordMail;
use App\Models\EmailTemplate;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserLog;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($this->admin);
    }

    public function test_resend_temp_password_only_for_users_not_logged_in(): void
    {
        $pending = User::factory()->create(['role' => UserRole::Student, 'password_changed_at' => null]);
        $active = User::factory()->create(['role' => UserRole::Student]);

        Livewire::test(ManageUsers::class)
            ->assertCanSeeTableRecords([$pending, $active])
            ->assertActionVisible(TestAction::make('resendTempPassword')->table($pending))
            ->assertActionHidden(TestAction::make('resendTempPassword')->table($active))
            ->assertActionHidden(TestAction::make('resetPassword')->table($pending))
            ->callAction(TestAction::make('resendTempPassword')->table($pending))
            ->assertNotified();

        Mail::assertSent(TempPasswordMail::class, fn ($m) => $m->hasTo($pending->email));
        $pending->refresh();
        $this->assertNull($pending->temp_password_expires_at);

        $log = $pending->logs()->where('event', UserLogEvent::TempPasswordSent)->first();
        $this->assertTrue($log->actor->is($this->admin));
        $this->assertSame('admin_resend', $log->meta['reason']);
    }

    public function test_reset_password_sends_expiring_temp_password(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);

        Livewire::test(ManageUsers::class)
            ->callAction(TestAction::make('resetPassword')->table($student))
            ->assertNotified();

        $student->refresh();
        $this->assertNotNull($student->temp_password);
        $this->assertTrue($student->temp_password_expires_at->isFuture());
    }

    public function test_create_student_without_password_sends_temp_password(): void
    {
        Livewire::test(ManageUsers::class)
            ->callAction('create', [
                'name' => 'Học viên mới',
                'email' => 'moi@example.com',
                'role' => UserRole::Student->value,
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $user = User::where('email', 'moi@example.com')->firstOrFail();
        $this->assertNull($user->password_changed_at);
        $this->assertNotNull($user->referral_code);
        Mail::assertSent(TempPasswordMail::class, fn ($m) => $m->hasTo('moi@example.com'));
    }

    public function test_invite_creates_student_and_sends_invite_email(): void
    {
        User::factory()->create(['email' => 'trung@example.com']);

        Livewire::test(ManageUsers::class)
            ->callAction('invite', ['name' => 'X', 'email' => 'TRUNG@example.com'])
            ->assertHasActionErrors(['email']);

        Livewire::test(ManageUsers::class)
            ->callAction('invite', ['name' => 'Trần Thị Mai', 'email' => 'Mai@Example.com', 'phone' => '+84 912 345 678'])
            ->assertHasNoActionErrors()
            ->assertNotified('Đã gửi email mời tới mai@example.com');

        $user = User::where('email', 'mai@example.com')->firstOrFail();
        $this->assertSame(UserRole::Student, $user->role);
        $this->assertSame('0912345678', $user->phone);
        $this->assertFalse($user->hasActivatedPassword());
        $this->assertNull($user->temp_password_expires_at);
        $this->assertTrue($user->wasInvited());

        Mail::assertSent(TempPasswordMail::class, fn (TempPasswordMail $m) => $m->hasTo('mai@example.com')
            && $m->template->key === EmailTemplate::TEMP_PASSWORD_INVITE
            && $m->envelope()->subject === 'Trần Thị Mai ơi, Ellso đã tạo tài khoản cho bạn'
            && str_contains($m->render(), 'Mời bạn tham gia cùng cộng đồng Ellso'));

        $this->assertSame('admin_created', $user->logs()->where('event', UserLogEvent::TempPasswordSent)->first()->meta['reason']);
    }

    public function test_resend_uses_invite_email_only_for_invited_users(): void
    {
        $invited = User::factory()->create(['role' => UserRole::Student, 'password_changed_at' => null]);
        $selfRegistered = User::factory()->create(['role' => UserRole::Student, 'password_changed_at' => null]);
        UserLog::record($selfRegistered, UserLogEvent::Registered);

        Livewire::test(ManageUsers::class)
            ->assertActionHasLabel(TestAction::make('resendTempPassword')->table($invited), 'Gửi lại email mời')
            ->assertActionHasLabel(TestAction::make('resendTempPassword')->table($selfRegistered), 'Gửi lại mật khẩu tạm')
            ->callAction(TestAction::make('resendTempPassword')->table($invited))
            ->callAction(TestAction::make('resendTempPassword')->table($selfRegistered));

        Mail::assertSent(TempPasswordMail::class, fn ($m) => $m->hasTo($invited->email)
            && $m->template->key === EmailTemplate::TEMP_PASSWORD_INVITE);
        Mail::assertSent(TempPasswordMail::class, fn ($m) => $m->hasTo($selfRegistered->email)
            && $m->template->key === EmailTemplate::TEMP_PASSWORD_ACTIVATION);
    }

    public function test_logs_page_renders(): void
    {
        $user = User::factory()->create();
        $log = UserLog::create([
            'user_id' => $user->id,
            'event' => UserLogEvent::Login,
            'device_name' => 'Pixel 9',
            'platform' => 'android',
            'ip_address' => '1.2.3.4',
        ]);

        Livewire::test(ManageUserLogs::class, ['record' => $user->getRouteKey()])
            ->assertOk()
            ->assertCanSeeTableRecords([$log])
            ->assertSee('Pixel 9');

        $this->get(ManageUserLogs::getUrl(['record' => $user]))->assertOk();
    }

    public function test_settings_can_be_edited_and_cache_is_refreshed(): void
    {
        $this->get(ManageSystemSettings::getUrl())->assertOk()->assertSee('Tỉ lệ hoa hồng');

        $this->assertSame(10.0, SystemSetting::referralCommissionPercent());
        $setting = SystemSetting::where('key', SystemSetting::REFERRAL_COMMISSION_PERCENT)->first();

        Livewire::test(ManageSystemSettings::class)
            ->callAction(TestAction::make('edit')->table($setting), ['value' => 150])
            ->assertHasActionErrors(['value']);

        Livewire::test(ManageSystemSettings::class)
            ->callAction(TestAction::make('edit')->table($setting), ['value' => 12.5])
            ->assertHasNoActionErrors();

        $this->assertSame(12.5, SystemSetting::referralCommissionPercent());
        $this->assertSame(1000, SystemSetting::creditToVndRate());
    }

    public function test_non_admin_cannot_open_settings_or_logs(): void
    {
        $instructor = User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]);
        $this->actingAs($instructor);

        $this->get(ManageSystemSettings::getUrl())->assertForbidden();
        $this->get(ManageUserLogs::getUrl(['record' => $this->admin]))->assertForbidden();
    }
}

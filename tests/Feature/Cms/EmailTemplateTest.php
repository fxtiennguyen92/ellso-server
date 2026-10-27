<?php

namespace Tests\Feature\Cms;

use App\Enums\TempPasswordReason;
use App\Enums\UserRole;
use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Mail\TempPasswordMail;
use App\Models\EmailTemplate;
use App\Models\SystemSetting;
use App\Models\User;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
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

    public function test_admin_edits_template_and_mail_uses_it(): void
    {
        $template = EmailTemplate::forKey(EmailTemplate::TEMP_PASSWORD_ACTIVATION);
        $undoRepeaterFake = Repeater::fake();

        $this->get(ListEmailTemplates::getUrl())->assertOk()->assertSee('Mật khẩu tạm – kích hoạt tài khoản');
        $this->get(EditEmailTemplate::getUrl(['record' => $template]))->assertOk();

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm([
                'subject' => 'Chào {ten}, đây là mật khẩu của bạn',
                'heading' => 'Rất vui được gặp bạn',
                'intro' => 'Xin chào **{ten}** <script>alert(1)</script>',
                'steps' => [['step' => 'Bước một'], ['step' => 'Bước hai']],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();
        $template->refresh();
        $this->assertSame(['Bước một', 'Bước hai'], $template->steps);
        $this->assertTrue($template->updatedBy->is($this->admin));

        $user = User::factory()->make(['name' => 'Lan', 'email' => 'lan@example.com']);
        $mail = new TempPasswordMail($user, 'Abc123Def456', TempPasswordReason::Register, null);

        $this->assertSame('Chào Lan, đây là mật khẩu của bạn', $mail->envelope()->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Rất vui được gặp bạn', $html);
        $this->assertStringContainsString('Xin chào <strong>Lan</strong>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Bước hai', $html);
        $this->assertStringContainsString('Abc123Def456', $html);
        $this->assertStringContainsString('cskh@ellso.edu.vn', $html);
        $this->assertSame('cskh@ellso.edu.vn', $mail->envelope()->replyTo[0]->address);
    }

    public function test_unknown_variable_is_rejected(): void
    {
        $template = EmailTemplate::forKey(EmailTemplate::TEMP_PASSWORD_ACTIVATION);

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['intro' => 'Xin chào {tne}, hạn dùng {han_dung}'])
            ->call('save')
            ->assertHasFormErrors(['intro']);

        // {han_dung} hợp lệ ở mẫu quên mật khẩu
        $reset = EmailTemplate::forKey(EmailTemplate::TEMP_PASSWORD_RESET);
        Livewire::test(EditEmailTemplate::class, ['record' => $reset->getRouteKey()])
            ->fillForm(['intro' => 'Hết hạn lúc {han_dung}'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_preview_and_send_test_use_unsaved_content(): void
    {
        $template = EmailTemplate::forKey(EmailTemplate::TEMP_PASSWORD_RESET);

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['heading' => 'Tiêu đề chưa lưu'])
            ->mountAction('preview')
            ->assertMountedActionModalSee(['Tiêu đề chưa lưu', 'Thông tin yêu cầu']);

        Livewire::test(EditEmailTemplate::class, ['record' => $template->getRouteKey()])
            ->fillForm(['heading' => 'Tiêu đề gửi thử'])
            ->callAction('sendTest')
            ->assertNotified();

        Mail::assertSent(TempPasswordMail::class, fn ($m) => $m->hasTo($this->admin->email)
            && $m->template->heading === 'Tiêu đề gửi thử');

        $this->assertNotSame('Tiêu đề gửi thử', $template->fresh()->heading);
    }

    public function test_footer_shows_company_settings(): void
    {
        SystemSetting::where('key', SystemSetting::COMPANY_NAME)->first()->update(['value' => 'Công ty TNHH Ellso']);
        SystemSetting::where('key', SystemSetting::COMPANY_ADDRESS)->first()->update(['value' => '1 Lê Lợi, Q.1, TP.HCM']);

        $user = User::factory()->make();
        $html = (new TempPasswordMail($user, 'x', TempPasswordReason::Forgot, now()->addMinutes(30)))->render();

        $this->assertStringContainsString('Công ty TNHH Ellso · 1 Lê Lợi, Q.1, TP.HCM', $html);
        $this->assertStringContainsString('(30 phút)', $html);
    }

    public function test_instructor_cannot_open_templates(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));

        $this->get(ListEmailTemplates::getUrl())->assertForbidden();
    }
}

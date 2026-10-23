<?php

namespace Tests\Feature\Api;

use App\Enums\UserLogEvent;
use App\Mail\TempPasswordMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = [
        'X-Device-Name' => 'iPhone 15 Pro',
        'X-Device-Platform' => 'ios',
        'X-Device-OS' => '18.1',
        'X-App-Version' => '1.0.0',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function sentTempPassword(string $email): string
    {
        $password = null;

        Mail::assertSent(TempPasswordMail::class, function (TempPasswordMail $mail) use ($email, &$password) {
            if ($mail->hasTo($email)) {
                $password = $mail->tempPassword;
            }

            return true;
        });

        $this->assertNotNull($password, "Không có mail gửi tới {$email}");

        return $password;
    }

    public function test_full_registration_flow_logs_in_after_setting_password(): void
    {
        $referrer = User::factory()->create(['name' => 'Chị Lan']);

        $this->getJson('/v1/referrals/' . strtolower($referrer->referral_code))
            ->assertOk()
            ->assertJson(['name' => 'Chị Lan', 'referral_code' => $referrer->referral_code]);

        $this->postJson('/v1/auth/register', [
            'name' => 'Nguyễn Văn A',
            'email' => 'A@Example.com ',
            'phone' => '+84 912 345 678',
            'referral_code' => $referrer->referral_code,
        ])->assertCreated();

        $user = User::where('email', 'a@example.com')->firstOrFail();
        $this->assertSame('0912345678', $user->phone);
        $this->assertTrue($user->referrer->is($referrer));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $user->referral_code);
        $this->assertNull($user->password_changed_at);
        $this->assertNull($user->temp_password_expires_at);

        $temp = $this->sentTempPassword('a@example.com');
        $this->assertSame(12, strlen($temp));

        $login = $this->withHeaders(self::DEVICE)
            ->postJson('/v1/auth/login', ['email' => 'a@example.com', 'password' => $temp])
            ->assertOk()
            ->assertJson(['requires_password_change' => true]);

        $changeToken = $login->json('change_token');

        // Token đổi mật khẩu không gọi được API khác
        $this->withToken($changeToken)->getJson('/v1/me')->assertForbidden();

        $this->withToken($changeToken)->postJson('/v1/auth/set-password', [
            'password' => $temp, 'password_confirmation' => $temp,
        ])->assertUnprocessable();

        $set = $this->withToken($changeToken)->withHeaders(self::DEVICE)->postJson('/v1/auth/set-password', [
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
        ])->assertOk()->assertJson(['requires_password_change' => false]);

        $this->app['auth']->forgetGuards();
        $this->withToken($set->json('token'))->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('user', null)
            ->assertJsonPath('data.referrer.name', 'Chị Lan');

        $user->refresh();
        $this->assertNotNull($user->password_changed_at);
        $this->assertNotNull($user->last_login_at);
        $this->assertNull($user->temp_password);

        $log = $user->logs()->where('event', UserLogEvent::Login)->latest('id')->first();
        $this->assertSame('iPhone 15 Pro', $log->device_name);
        $this->assertSame('ios', $log->platform);

        // Mật khẩu tạm không còn dùng được, mật khẩu mới thì được
        $this->postJson('/v1/auth/login', ['email' => 'a@example.com', 'password' => $temp])->assertUnprocessable();
        $this->postJson('/v1/auth/login', ['email' => 'a@example.com', 'password' => 'matkhau123'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['referral_code']]);
    }

    public function test_register_validation(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/v1/auth/register', [
            'name' => 'B',
            'email' => 'TAKEN@example.com',
            'phone' => '12345',
            'referral_code' => 'ZZZZZZZZ',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone', 'referral_code']);

        $this->getJson('/v1/referrals/ZZZZZZZZ')->assertNotFound();
        Mail::assertNothingSent();
    }

    public function test_forgot_password_keeps_old_password_and_expires(): void
    {
        $user = User::factory()->create(['email' => 'b@example.com', 'password' => 'cu123456']);

        $this->postJson('/v1/auth/forgot-password', ['email' => 'b@example.com'])->assertOk();
        $temp = $this->sentTempPassword('b@example.com');

        // Mật khẩu cũ vẫn dùng được
        $this->postJson('/v1/auth/login', ['email' => 'b@example.com', 'password' => 'cu123456'])
            ->assertOk()->assertJson(['requires_password_change' => false]);
        // ...và đăng nhập bằng mật khẩu cũ thì hủy mật khẩu tạm
        $this->postJson('/v1/auth/login', ['email' => 'b@example.com', 'password' => $temp])->assertUnprocessable();

        // Mật khẩu tạm hết hạn sau 30 phút
        $user->forceFill(['temp_password_sent_at' => now()->subHour()])->save();
        $this->postJson('/v1/auth/forgot-password', ['email' => 'b@example.com'])->assertOk();
        $temp2 = null;
        Mail::assertSent(TempPasswordMail::class, function ($m) use (&$temp2) {
            $temp2 = $m->tempPassword;

            return true;
        });
        $this->travel(31)->minutes();
        $this->postJson('/v1/auth/login', ['email' => 'b@example.com', 'password' => $temp2])->assertUnprocessable();
    }

    public function test_forgot_password_anti_spam(): void
    {
        $user = User::factory()->create(['email' => 'c@example.com']);

        // Email không tồn tại: cùng câu trả lời, không gửi gì
        $this->postJson('/v1/auth/forgot-password', ['email' => 'khongco@example.com'])->assertOk();
        Mail::assertNothingSent();

        $this->postJson('/v1/auth/forgot-password', ['email' => 'c@example.com'])->assertOk();
        $this->postJson('/v1/auth/forgot-password', ['email' => 'c@example.com'])->assertOk(); // trong cooldown
        Mail::assertSentCount(1);
        $this->assertTrue($user->logs()->where('event', UserLogEvent::ForgotPasswordThrottled)->exists());

        // Giới hạn theo IP
        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/v1/auth/forgot-password', ['email' => "x{$i}@example.com"]);
        }
        $this->postJson('/v1/auth/forgot-password', ['email' => 'y@example.com'])->assertStatus(429);
    }

    public function test_login_failures_are_throttled_and_locked_account_rejected(): void
    {
        $user = User::factory()->create(['email' => 'd@example.com', 'password' => 'dung12345']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/v1/auth/login', ['email' => 'd@example.com', 'password' => 'sai'])->assertUnprocessable();
        }
        $this->postJson('/v1/auth/login', ['email' => 'd@example.com', 'password' => 'dung12345'])->assertStatus(429);
        $this->assertSame(5, $user->logs()->where('event', UserLogEvent::LoginFailed)->count());

        $locked = User::factory()->create(['email' => 'e@example.com', 'password' => 'dung12345', 'is_active' => false]);
        $this->postJson('/v1/auth/login', ['email' => 'e@example.com', 'password' => 'dung12345'])->assertForbidden();
    }

    public function test_logout_revokes_token(): void
    {
        User::factory()->create(['email' => 'f@example.com', 'password' => 'dung12345']);
        $token = $this->postJson('/v1/auth/login', ['email' => 'f@example.com', 'password' => 'dung12345'])->json('token');

        $this->withToken($token)->postJson('/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_full_token_cannot_use_set_password(): void
    {
        User::factory()->create(['email' => 'g@example.com', 'password' => 'dung12345']);
        $token = $this->postJson('/v1/auth/login', ['email' => 'g@example.com', 'password' => 'dung12345'])->json('token');

        $this->withToken($token)->postJson('/v1/auth/set-password', [
            'password' => 'moi123456', 'password_confirmation' => 'moi123456',
        ])->assertForbidden();
    }
}

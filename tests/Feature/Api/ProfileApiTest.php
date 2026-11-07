<?php

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Enums\MembershipSource;
use App\Models\StudentMembership;
use App\Models\User;
use App\Support\MediaStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** App › Hồ sơ: sửa thông tin, ảnh đại diện, đổi mật khẩu. */
class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => UserRole::Student, 'is_active' => true, 'name' => 'Cũ', 'password' => 'MatKhau123']);
        $this->token = $this->user->createToken('app', ['app'])->plainTextToken;
    }

    public function test_update_name_and_phone(): void
    {
        $this->withToken($this->token)->patchJson('/v1/me', ['name' => '  Nguyễn Văn A ', 'phone' => '+84 912 345 678'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nguyễn Văn A')
            ->assertJsonPath('data.phone', '0912345678');

        $this->withToken($this->token)->patchJson('/v1/me', ['name' => '', 'phone' => '12345'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone']);

        // Xoá số điện thoại
        $this->withToken($this->token)->patchJson('/v1/me', ['name' => 'A', 'phone' => ''])->assertOk()->assertJsonPath('data.phone', null);
    }

    public function test_avatar_upload_replace_and_delete(): void
    {
        Storage::fake(MediaStorage::diskName());

        $first = $this->withToken($this->token)->post('/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('a.jpg', 512, 512)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.avatar');
        $this->assertNotNull($first);
        $path1 = $this->user->fresh()->avatar;
        Storage::disk(MediaStorage::diskName())->assertExists($path1);

        // Đổi ảnh → link mới, ảnh cũ bị xoá
        $second = $this->withToken($this->token)->post('/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('b.png', 400, 400)], ['Accept' => 'application/json'])
            ->assertOk()->json('data.avatar');
        $this->assertNotSame($first, $second);
        Storage::disk(MediaStorage::diskName())->assertMissing($path1);

        // Không phải ảnh / quá nhỏ
        $this->withToken($this->token)->post('/v1/me/avatar', ['avatar' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $this->withToken($this->token)->post('/v1/me/avatar', ['avatar' => UploadedFile::fake()->image('tiny.jpg', 20, 20)], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $path2 = $this->user->fresh()->avatar;
        $this->withToken($this->token)->deleteJson('/v1/me/avatar')->assertOk()->assertJsonPath('data.avatar', null);
        Storage::disk(MediaStorage::diskName())->assertMissing($path2);
    }

    public function test_change_password_requires_current_and_rules_and_logs_out_other_devices(): void
    {
        $other = $this->user->createToken('app', ['app'])->plainTextToken;

        $this->withToken($this->token)->postJson('/v1/me/password', ['current_password' => 'sai', 'password' => 'MoiMoi123', 'password_confirmation' => 'MoiMoi123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
        $this->withToken($this->token)->postJson('/v1/me/password', ['current_password' => 'MatKhau123', 'password' => 'yeu', 'password_confirmation' => 'yeu'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->withToken($this->token)->postJson('/v1/me/password', ['current_password' => 'MatKhau123', 'password' => 'MatKhau123', 'password_confirmation' => 'MatKhau123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->withToken($this->token)->postJson('/v1/me/password', ['current_password' => 'MatKhau123', 'password' => 'MoiMoi123', 'password_confirmation' => 'MoiMoi123'])
            ->assertOk();
        $this->assertTrue(Hash::check('MoiMoi123', $this->user->fresh()->password));
        $this->assertSame(1, $this->user->tokens()->count()); // chỉ còn thiết bị đang dùng

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/v1/me')->assertUnauthorized();
    }

    public function test_add_referrer_once_with_preview(): void
    {
        $ref = User::factory()->create(['role' => UserRole::Student, 'is_active' => true, 'name' => 'Người GT', 'email' => 'gt@ellso.test']);

        $this->withToken($this->token)->getJson('/v1/me/referrer/preview?code=' . strtolower($ref->referral_code))
            ->assertOk()
            ->assertJsonPath('data.name', 'Người GT')
            ->assertJsonPath('data.email', 'gt@ellso.test');

        $this->withToken($this->token)->postJson('/v1/me/referrer', ['code' => 'KHONGCO1'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->withToken($this->token)->postJson('/v1/me/referrer', ['code' => $this->user->referral_code])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->withToken($this->token)->postJson('/v1/me/referrer', ['code' => $ref->referral_code])
            ->assertOk()
            ->assertJsonPath('data.referrer.name', 'Người GT')
            ->assertJsonPath('data.referrer.email', 'gt@ellso.test');
        $this->assertSame($ref->id, $this->user->fresh()->referred_by_id);

        // đã có thì không đổi được
        $other = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
        $this->withToken($this->token)->postJson('/v1/me/referrer', ['code' => $other->referral_code])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame($ref->id, $this->user->fresh()->referred_by_id);
    }

    public function test_cannot_add_own_referral_as_referrer(): void
    {
        $child = User::factory()->create(['role' => UserRole::Student, 'is_active' => true, 'referred_by_id' => $this->user->id]);
        $this->withToken($this->token)->postJson('/v1/me/referrer', ['code' => $child->referral_code])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_membership_started_at_follows_renewal_chain(): void
    {
        $this->withToken($this->token)->getJson('/v1/me')->assertJsonPath('data.membership.active', false)->assertJsonPath('data.membership.started_at', null);

        $first = now()->subMonths(13)->startOfDay();
        foreach ([[$first, $first->copy()->addYear()], [$first->copy()->addYear(), $first->copy()->addYears(2)]] as [$a, $b]) {
            StudentMembership::create(['user_id' => $this->user->id, 'starts_at' => $a, 'ends_at' => $b, 'price' => 0, 'source' => MembershipSource::cases()[0]]);
        }

        $this->withToken($this->token)->getJson('/v1/me')
            ->assertJsonPath('data.membership.active', true)
            ->assertJsonPath('data.membership.started_at', $first->toIso8601String());
    }

    public function test_devices_list_and_remote_logout(): void
    {
        $u = User::factory()->create(['role' => UserRole::Student, 'is_active' => true, 'email' => 'dev@example.com']);
        $u->setOwnPassword('MatKhau123');
        $login = fn (string $id, string $name, string $platform) => $this->withHeaders([
            'X-Device-Id' => $id, 'X-Device-Name' => $name, 'X-Device-Platform' => $platform, 'X-Device-OS' => '18.1',
        ])->postJson('/v1/auth/login', ['email' => 'dev@example.com', 'password' => 'MatKhau123'])->assertOk()->json('token');

        $phone = $login('dev-phone', 'iPhone 15', 'ios');
        $web = $login('dev-web', 'Chrome', 'web');
        $phone = $login('dev-phone', 'iPhone 15', 'ios'); // đăng nhập lại cùng máy → thay phiên cũ, không nhân đôi
        $this->assertSame(2, $u->tokens()->count());

        $list = $this->withToken($phone)->getJson('/v1/me/devices')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['current']);
        $this->assertSame('ios', $list[0]['platform']);
        $this->assertSame('Chrome', $list[1]['name']);

        $this->withToken($phone)->deleteJson('/v1/me/devices/' . $list[0]['id'])->assertStatus(422)->assertJsonPath('code', 'current_device');
        $this->withToken($phone)->deleteJson('/v1/me/devices/' . $list[1]['id'])->assertOk();
        $this->assertSame(1, $u->tokens()->count());

        // token của máy đã bị đăng xuất không dùng được nữa; không xoá được token của người khác
        $this->app['auth']->forgetGuards();
        $this->withToken($web)->getJson('/v1/me')->assertUnauthorized();
        $otherId = $this->user->tokens()->first()->getKey();
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->deleteJson('/v1/me/devices/' . $otherId)->assertNotFound();
    }
}

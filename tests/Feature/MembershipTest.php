<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\MembershipSource;
use App\Enums\UserLogEvent;
use App\Enums\UserRole;
use App\Filament\Pages\Marketing\MembershipSettingsPage;
use App\Filament\Resources\MembershipCampaigns\Pages\ManageMembershipCampaigns;
use App\Filament\Resources\Students\Pages\ManageStudents as ManageUsers; // học viên quản lý ở Tài khoản › Học viên
use App\Models\Course;
use App\Models\MembershipBundleItem;
use App\Models\MembershipCampaign;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\MembershipService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class MembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Ho_Chi_Minh'));
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function campaign(string $from, string $to, bool $active = true): MembershipCampaign
    {
        return MembershipCampaign::create([
            'name' => 'Chiến dịch',
            'starts_at' => Carbon::parse($from, 'Asia/Ho_Chi_Minh'),
            'ends_at' => Carbon::parse($to, 'Asia/Ho_Chi_Minh'),
            'is_active' => $active,
        ]);
    }

    private function course(): Course
    {
        return Course::create(['title' => 'Khoá ' . uniqid(), 'instructor_id' => $this->admin->id, 'price' => 1_000_000, 'status' => CourseStatus::Published]);
    }

    public function test_grant_is_one_year_and_stacks_after_current_package(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $service = app(MembershipService::class);

        $first = $service->grant($student, MembershipSource::Purchase, 2_990_000);
        $this->assertTrue($first->starts_at->equalTo(now()));
        $this->assertTrue($first->ends_at->equalTo(now()->addYear()));

        // Đang có gói → gói mới nối tiếp
        $second = $service->grant($student, MembershipSource::Admin);
        $this->assertTrue($second->starts_at->equalTo($first->ends_at));
        $this->assertTrue($student->membershipEndsAt()->equalTo(now()->addYears(2)));
        $this->assertTrue($student->hasActiveMembership());
        $this->assertSame(2, $student->logs()->where('event', UserLogEvent::MembershipGranted)->count());

        // Gói đã hết hạn thì gói mới bắt đầu từ hôm nay
        $expired = User::factory()->create(['role' => UserRole::Student]);
        $expired->memberships()->create(['starts_at' => now()->subYears(2), 'ends_at' => now()->subYear(), 'source' => MembershipSource::Purchase]);
        $this->assertTrue($service->grant($expired, MembershipSource::Admin)->starts_at->equalTo(now()));
    }

    public function test_campaign_gives_free_package_to_students_created_during_campaign(): void
    {
        $campaign = $this->campaign('2026-11-01 00:00', '2026-11-30 23:59');
        $this->campaign('2026-11-01 00:00', '2026-11-30 23:59', active: false); // tạm dừng: không tính

        $student = User::factory()->create(['role' => UserRole::Student]);
        $teacher = User::factory()->create(['role' => UserRole::Instructor]);

        $this->assertSame(1, $student->memberships()->count());
        $m = $student->memberships()->first();
        $this->assertSame(MembershipSource::Campaign, $m->source);
        $this->assertSame(0, $m->price);
        $this->assertTrue($m->campaign->is($campaign));
        $this->assertSame(0, $teacher->memberships()->count());

        // Ngoài thời gian chiến dịch: không tặng
        $this->travelTo(Carbon::parse('2026-12-05 10:00', 'Asia/Ho_Chi_Minh'));
        $this->assertSame(0, User::factory()->create(['role' => UserRole::Student])->memberships()->count());
    }

    public function test_register_api_during_campaign_returns_membership_on_me(): void
    {
        $this->campaign('2026-11-01 00:00', '2026-11-30 23:59');

        $this->postJson('/v1/auth/register', ['name' => 'Lan', 'email' => 'lan@example.com'])->assertCreated();
        $user = User::where('email', 'lan@example.com')->firstOrFail();
        $user->update(['password' => 'Matkhau123', 'password_changed_at' => now()]);

        $token = $this->postJson('/v1/auth/login', ['email' => 'lan@example.com', 'password' => 'Matkhau123'])->json('token');
        $this->withToken($token)->getJson('/v1/me')
            ->assertOk()
            ->assertJsonPath('data.membership.active', true)
            ->assertJsonPath('data.membership.ends_at', now()->addYear()->toIso8601String());
    }

    public function test_backfill_gives_package_once_to_students_who_registered_earlier(): void
    {
        $this->travelTo(Carbon::parse('2026-11-05 10:00', 'Asia/Ho_Chi_Minh'));
        $early = User::factory()->create(['role' => UserRole::Student]);           // trước khi có chiến dịch
        $this->travelTo(Carbon::parse('2026-10-20 10:00', 'Asia/Ho_Chi_Minh'));
        $before = User::factory()->create(['role' => UserRole::Student]);          // trước thời gian chiến dịch
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Ho_Chi_Minh'));

        $campaign = $this->campaign('2026-11-01 00:00', '2026-11-30 23:59');
        $service = app(MembershipService::class);

        $this->assertSame(1, $service->eligibleForBackfill($campaign)->count());
        $this->assertSame(1, $service->backfillCampaign($campaign, $this->admin));
        $this->assertSame(0, $service->backfillCampaign($campaign, $this->admin)); // không tặng lần 2
        $this->assertTrue($early->hasActiveMembership());
        $this->assertFalse($before->hasActiveMembership());
    }

    public function test_purchase_of_bundled_course_grants_package(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $bundled = $this->course();
        $other = $this->course();
        MembershipBundleItem::sync('course', [$bundled->id]);

        $service = app(MembershipService::class);
        $this->assertNull($service->grantForPurchase($student, $other));

        $m = $service->grantForPurchase($student, $bundled, orderId: 99);
        $this->assertSame(MembershipSource::Bundle, $m->source);
        $this->assertSame(99, (int) $m->order_id);
        $this->assertTrue($m->sourceItem()->is($bundled));
    }

    public function test_cms_settings_page_saves_price_and_bundled_courses(): void
    {
        $this->actingAs($this->admin);
        $a = $this->course();
        $b = $this->course();

        $this->get(MembershipSettingsPage::getUrl())->assertOk()->assertSee('Mua kèm được tặng gói');

        Livewire::test(MembershipSettingsPage::class)
            ->fillForm(['price' => 2990000, 'courses' => [$a->id, $b->id]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(2_990_000, SystemSetting::studentMembershipPrice());
        $this->assertSame([$a->id, $b->id], MembershipBundleItem::idsOf('course'));

        Livewire::test(MembershipSettingsPage::class)
            ->assertFormSet(['courses' => [$a->id, $b->id]])
            ->fillForm(['price' => null, 'courses' => [$b->id]])
            ->call('save');

        $this->assertNull(SystemSetting::studentMembershipPrice());
        $this->assertSame([$b->id], MembershipBundleItem::idsOf('course'));
    }

    public function test_cms_campaigns_and_user_grant_action(): void
    {
        $this->actingAs($this->admin);

        $this->get(ManageMembershipCampaigns::getUrl())->assertOk();

        Livewire::test(ManageMembershipCampaigns::class)
            ->callAction('create', ['name' => 'Sai', 'starts_at' => '2026-11-10 08:00', 'ends_at' => '2026-11-01 08:00', 'is_active' => true])
            ->assertHasActionErrors(['ends_at']);

        Livewire::test(ManageMembershipCampaigns::class)
            ->callAction('create', ['name' => 'Khai trương', 'starts_at' => '2026-11-01 00:00', 'ends_at' => '2026-11-30 23:59', 'is_active' => true])
            ->assertHasNoActionErrors();

        $campaign = MembershipCampaign::where('name', 'Khai trương')->firstOrFail();
        $this->assertTrue($campaign->createdBy->is($this->admin));
        $this->assertSame('running', $campaign->state());

        // Học viên đăng ký trước khi tạo chiến dịch → tặng bù bằng nút trên CMS
        $student = User::withoutEvents(fn () => User::factory()->create(['role' => UserRole::Student]));
        Livewire::test(ManageMembershipCampaigns::class)
            ->callAction(TestAction::make('backfill')->table($campaign))
            ->assertNotified('Đã tặng gói cho 1 học viên');
        $this->assertTrue($student->hasActiveMembership());

        // Cấp tay từ màn Người dùng (cộng nối thêm 1 năm)
        Livewire::test(ManageUsers::class)
            ->assertActionHasLabel(TestAction::make('grantMembership')->table($student), 'Gia hạn gói thành viên')
            ->callAction(TestAction::make('grantMembership')->table($student), ['price' => 1500000, 'note' => 'Chuyển khoản'])
            ->assertNotified();

        $latest = $student->memberships()->latest('id')->first();
        $this->assertSame(MembershipSource::Admin, $latest->source);
        $this->assertSame(1_500_000, $latest->price);
        $this->assertTrue($latest->createdBy->is($this->admin));
        $this->assertTrue($student->membershipEndsAt()->equalTo(now()->addYears(2)));
    }

    public function test_instructor_cannot_open_membership_screens(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(MembershipSettingsPage::getUrl())->assertForbidden();
        $this->get(ManageMembershipCampaigns::getUrl())->assertForbidden();
    }
}

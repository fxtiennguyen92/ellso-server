<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\Statistics\StudentActivityPage;
use App\Filament\Statistics\Widgets\AppOpensChart;
use App\Filament\Statistics\Widgets\HourlyOpensChart;
use App\Filament\Statistics\Widgets\PlatformShareChart;
use App\Filament\Statistics\Widgets\StudentActivityOverview;
use App\Filament\Statistics\Widgets\WeekdayOpensChart;
use App\Models\AppSession;
use App\Models\StudentMembership;
use App\Models\User;
use App\Support\StudentActivityStatistics;
use App\Support\TimeBuckets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class StudentActivityTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE = [
        'X-Device-Id' => 'abc-123',
        'X-Device-Name' => 'iPhone 15',
        'X-Device-Platform' => 'ios',
        'X-Device-OS' => '18.1',
        'X-App-Version' => '1.0.0',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        StudentActivityStatistics::flush();
    }

    public function test_ping_creates_session_and_extends_within_30_minutes(): void
    {
        $student = User::factory()->create(['role' => UserRole::Student]);
        $token = $student->createToken('app')->plainTextToken;

        $first = $this->withToken($token)->withHeaders(self::DEVICE)->postJson('/v1/app-sessions/ping')
            ->assertCreated()
            ->assertJson(['new_session' => true, 'heartbeat_seconds' => 60, 'idle_timeout_seconds' => 1800]);

        // Heartbeat 20 phút sau → cùng phiên
        $this->travel(20)->minutes();
        $this->withToken($token)->withHeaders(self::DEVICE)->postJson('/v1/app-sessions/ping')
            ->assertOk()
            ->assertJson(['new_session' => false, 'session_id' => $first->json('session_id')]);

        // Thiết bị khác cùng lúc → phiên riêng
        $this->withToken($token)->withHeaders([...self::DEVICE, 'X-Device-Id' => 'other', 'X-Device-Platform' => 'web'])
            ->postJson('/v1/app-sessions/ping')->assertCreated();

        // Quay lại sau 31 phút không tín hiệu → lượt mở mới
        $this->travel(31)->minutes();
        $this->withToken($token)->withHeaders(self::DEVICE)->postJson('/v1/app-sessions/ping')
            ->assertCreated()->assertJson(['new_session' => true]);

        $this->assertSame(3, $student->appSessions()->count());
        $session = AppSession::find($first->json('session_id'));
        $this->assertSame('ios', $session->platform);
        $this->assertSame(2, $session->ping_count);
        $this->assertSame(20 * 60, $session->durationSeconds());

        $this->app['auth']->forgetGuards();
        $this->withoutToken()->postJson('/v1/app-sessions/ping')->assertUnauthorized();
    }

    public function test_statistics_count_students_only_in_vietnam_time(): void
    {
        $this->travelTo(Carbon::parse('2026-10-25 12:00', TimeBuckets::TZ)); // Chủ nhật

        $a = User::factory()->create(['role' => UserRole::Student]);
        $b = User::factory()->create(['role' => UserRole::Student]);
        User::factory()->create(['role' => UserRole::Student]); // chưa mở app
        $teacher = User::factory()->create(['role' => UserRole::Instructor]);

        $open = fn (User $u, string $at, ?string $platform, int $minutes = 10) => AppSession::create([
            'user_id' => $u->id,
            'platform' => $platform,
            'started_at' => Carbon::parse($at, TimeBuckets::TZ)->utc(),
            'last_seen_at' => Carbon::parse($at, TimeBuckets::TZ)->addMinutes($minutes)->utc(),
        ]);

        $open($a, '2026-10-19 20:15', 'ios');      // T2, 20h
        $open($a, '2026-10-19 21:05', 'ios', 30);  // T2, 21h
        $open($b, '2026-10-20 00:30', 'android');  // T3 0h giờ VN (17:30 UTC ngày 19)
        $open($b, '2026-10-25 08:00', null);       // CN, nền tảng không rõ → Khác
        $open($teacher, '2026-10-20 09:00', 'web'); // giảng viên: không tính
        $open($a, '2026-09-01 10:00', 'web');      // ngoài khoảng lọc nhưng tính vào thẻ toàn thời gian

        StudentMembership::create(['user_id' => $a->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(11)]);
        StudentMembership::create(['user_id' => $b->id, 'starts_at' => now()->subYears(2), 'ends_at' => now()->subYear()]);

        $overview = StudentActivityStatistics::overview();
        $this->assertSame(3, $overview['students']);
        $this->assertSame(1, $overview['active_members']);
        $this->assertSame(2, $overview['ever_members']);
        $this->assertEqualsWithDelta(33.33, $overview['membership_rate'], 0.01);
        $this->assertSame(['ios' => 2, 'android' => 1, 'web' => 1, 'other' => 1], $overview['platforms']);

        $a = (new StudentActivityStatistics(['from' => '2026-10-19', 'to' => '2026-10-25', 'group_by' => 'day']))->activity();
        $this->assertSame(['19/10', '20/10', '21/10', '22/10', '23/10', '24/10', '25/10'], $a['labels']);
        $this->assertSame([2, 1, 0, 0, 0, 0, 1], $a['opens']);
        $this->assertSame(4, $a['total']);
        $this->assertSame(2, $a['students']);
        $this->assertEqualsWithDelta(15.0, $a['avg_minutes'], 0.01); // (10+30+10+10)/4
        $this->assertSame(['ios' => 2, 'android' => 1, 'web' => 0, 'other' => 1], $a['platforms']);
        $this->assertSame([1 => 2, 2 => 1, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 1], $a['weekdays']);
        $this->assertSame(1, $a['hours'][0]);
        $this->assertSame(1, $a['hours'][20]);
        $this->assertSame(1, $a['hours'][21]);
        $this->assertSame(1, $a['hours'][8]);
    }

    public function test_page_and_widgets_render(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]));
        $student = User::factory()->create(['role' => UserRole::Student]);
        AppSession::create(['user_id' => $student->id, 'platform' => 'android', 'started_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(50)]);

        $this->get(StudentActivityPage::getUrl())->assertOk()
            ->assertSee('Hoạt động học viên')
            ->assertSee('Tổng học viên')
            ->assertSee('Android 100%');

        $filters = ['from' => now(TimeBuckets::TZ)->subDays(6)->toDateString(), 'to' => now(TimeBuckets::TZ)->toDateString(), 'group_by' => 'day'];

        Livewire::test(StudentActivityPage::class)
            ->fillForm(['from' => '2026-01-01', 'to' => '2025-12-01'])
            ->call('apply')
            ->assertHasFormErrors(['to']);

        Livewire::test(StudentActivityOverview::class)->assertSee('Tỉ lệ có gói học viên')->assertSee('0%');
        Livewire::test(AppOpensChart::class, ['pageFilters' => $filters])->assertOk()->assertSee('1 lượt từ 1 học viên');
        Livewire::test(PlatformShareChart::class, ['pageFilters' => $filters])->assertOk()->assertSee('Tỉ lệ nền tảng');
        Livewire::test(WeekdayOpensChart::class, ['pageFilters' => $filters])->assertOk();
        Livewire::test(HourlyOpensChart::class, ['pageFilters' => $filters])->assertOk();
    }

    public function test_only_admin_can_open(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(StudentActivityPage::getUrl())->assertForbidden();
    }
}

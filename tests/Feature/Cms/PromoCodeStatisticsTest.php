<?php

namespace Tests\Feature\Cms;

use App\Enums\DiscountType;
use App\Enums\UserRole;
use App\Filament\Pages\Statistics\PromoCodeStatisticsPage;
use App\Filament\Statistics\Widgets\PromoCodeRevenueChart;
use App\Filament\Statistics\Widgets\PromoCodeStatsOverview;
use App\Filament\Statistics\Widgets\PromoCodeUsageChart;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use App\Support\PromoCodeStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class PromoCodeStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private PromoCode $a;

    private PromoCode $b;

    protected function setUp(): void
    {
        parent::setUp();

        // 12:00 25/10/2026 giờ VN
        $this->travelTo(Carbon::parse('2026-10-25 12:00', PromoCodeStatistics::TZ));

        $this->actingAs(User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]));

        $this->a = PromoCode::create(['code' => 'AAAAAAAA', 'name' => 'Chiến dịch A', 'discount_type' => DiscountType::Percent, 'discount_value' => 10]);
        $this->b = PromoCode::create(['code' => 'BBBBBBBB', 'name' => 'Chiến dịch B', 'discount_type' => DiscountType::Fixed, 'discount_value' => 100_000]);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        // [mã, user, giá gốc, giảm, thời điểm giờ VN]
        foreach ([
            [$this->a, $u1, 1_000_000, 100_000, '2026-10-20 09:00'],
            [$this->a, $u1, 2_000_000, 200_000, '2026-10-20 23:30'], // 16:30 UTC, vẫn là ngày 20 giờ VN
            [$this->a, $u2, 1_000_000, 100_000, '2026-10-21 00:30'], // 17:30 UTC ngày 20, nhưng là ngày 21 giờ VN
            [$this->b, $u2, 500_000, 100_000, '2026-10-22 10:00'],
            [$this->b, $u2, 500_000, 100_000, '2026-08-01 10:00'],   // ngoài khoảng mặc định
        ] as [$promo, $user, $amount, $discount, $at]) {
            PromoCodeRedemption::create([
                'promo_code_id' => $promo->id,
                'user_id' => $user->id,
                'order_amount' => $amount,
                'discount_amount' => $discount,
            ])->forceFill(['created_at' => Carbon::parse($at, PromoCodeStatistics::TZ)->utc()])->save();
        }
    }

    public function test_summary_and_daily_series_use_vietnam_time(): void
    {
        $stats = new PromoCodeStatistics(['from' => '2026-10-20', 'to' => '2026-10-22', 'group_by' => 'day', 'codes' => []]);

        $this->assertSame(['customers' => 2, 'orders' => 4, 'discount' => 500_000, 'gross' => 4_500_000, 'revenue' => 4_000_000], $stats->summary());

        $series = $stats->series();
        $this->assertSame(['20/10', '21/10', '22/10'], $series['labels']);
        $this->assertSame([2, 1, 1], $series['usage']);
        $this->assertSame([2_700_000, 900_000, 400_000], $series['revenue']);
    }

    public function test_filter_by_code_and_group_by_week_and_month(): void
    {
        $onlyB = new PromoCodeStatistics(['from' => '2026-08-01', 'to' => '2026-10-25', 'group_by' => 'month', 'codes' => [$this->b->id]]);
        $this->assertSame(2, $onlyB->summary()['orders']);
        $this->assertSame(['Th8/2026', 'Th9/2026', 'Th10/2026'], $onlyB->series()['labels']);
        $this->assertSame([1, 0, 1], $onlyB->series()['usage']);

        // Tuần bắt đầu thứ Hai: 19/10 – 25/10
        $weekly = new PromoCodeStatistics(['from' => '2026-10-12', 'to' => '2026-10-25', 'group_by' => 'week', 'codes' => []]);
        $this->assertSame(['Tuần 12/10', 'Tuần 19/10'], $weekly->series()['labels']);
        $this->assertSame([0, 4], $weekly->series()['usage']);
    }

    public function test_page_applies_filters_only_on_submit(): void
    {
        $this->get(PromoCodeStatisticsPage::getUrl())->assertOk()->assertSee('Hiển thị');

        $page = Livewire::test(PromoCodeStatisticsPage::class)
            ->assertSet('filters.from', '2026-09-26')
            ->assertSet('filters.group_by', 'day')
            ->fillForm(['codes' => [$this->a->id], 'from' => '2026-10-20', 'to' => '2026-10-21', 'group_by' => 'week']);

        // Chưa bấm Hiển thị: bộ lọc áp dụng chưa đổi
        $page->assertSet('filters.from', '2026-09-26');

        $page->call('apply')
            ->assertHasNoFormErrors()
            ->assertSet('filters.from', '2026-10-20')
            ->assertSet('filters.codes', [$this->a->id]);

        $page->fillForm(['from' => '2026-10-22', 'to' => '2026-10-20'])
            ->call('apply')
            ->assertHasFormErrors(['to']);

        $page->fillForm(['from' => '2025-01-01', 'to' => '2026-10-20', 'group_by' => 'day'])
            ->call('apply')
            ->assertHasFormErrors(['to']);
    }

    public function test_widgets_render_with_filters(): void
    {
        $filters = ['codes' => [$this->a->id], 'from' => '2026-10-20', 'to' => '2026-10-22', 'group_by' => 'day'];

        Livewire::test(PromoCodeStatsOverview::class, ['pageFilters' => $filters])
            ->assertSee('Khách dùng mã')
            ->assertSee('3')                 // 3 đơn mã A
            ->assertSee(PromoCode::money(400_000))   // tổng giảm
            ->assertSee(PromoCode::money(3_600_000)); // doanh thu

        Livewire::test(PromoCodeUsageChart::class, ['pageFilters' => $filters])->assertOk()->assertSee('Số lần sử dụng mã');
        Livewire::test(PromoCodeRevenueChart::class, ['pageFilters' => $filters])->assertOk()->assertSee('20/10/2026 – 22/10/2026');
    }

    public function test_only_admin_can_open(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(PromoCodeStatisticsPage::getUrl())->assertForbidden();
    }
}

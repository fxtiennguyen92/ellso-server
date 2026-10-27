<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\DiscountType;
use App\Enums\UserRole;
use App\Filament\Resources\MarketingCampaigns\Pages\CreateMarketingCampaign;
use App\Filament\Resources\MarketingCampaigns\Pages\EditMarketingCampaign;
use App\Filament\Resources\MarketingCampaigns\Pages\ListMarketingCampaigns;
use App\Models\Course;
use App\Models\MarketingCampaign;
use App\Models\PromoCode;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MarketingCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function campaign(array $attributes = []): MarketingCampaign
    {
        Storage::disk('public')->put('campaigns/banners/b.jpg', 'x');

        return MarketingCampaign::create([
            'title' => 'Ưu đãi tháng 11',
            'summary' => 'Giảm 30% gói thành viên',
            'banner_path' => 'campaigns/banners/b.jpg',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
            ...$attributes,
        ]);
    }

    public function test_api_lists_only_visible_campaigns_in_order(): void
    {
        $second = $this->campaign(['title' => 'Thứ hai', 'sort_order' => 2]);
        $first = $this->campaign(['title' => 'Thứ nhất', 'sort_order' => 1]);
        $this->campaign(['title' => 'Đang ẩn', 'is_active' => false]);
        $this->campaign(['title' => 'Sắp tới', 'starts_at' => now()->addDay()]);
        $this->campaign(['title' => 'Đã hết', 'ends_at' => now()->subMinute()]);

        $this->getJson('/v1/campaigns')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.0.banner.width', 1280)
            ->assertJsonPath('data.0.banner.height', 720)
            ->assertJsonPath('data.0.banner.url', Storage::disk('public')->url('campaigns/banners/b.jpg'));
    }

    public function test_api_detail_resolves_blocks_and_cta(): void
    {
        $promo = PromoCode::create(['code' => 'THANG11VIP', 'name' => 'T11', 'discount_type' => DiscountType::Percent, 'discount_value' => 30, 'max_discount' => 500_000]);
        $course = Course::create(['title' => 'Lãnh đạo', 'instructor_id' => $this->admin->id, 'price' => 1, 'status' => CourseStatus::Published]);
        Storage::disk('public')->put('campaigns/content/a.jpg', 'x');

        $c = $this->campaign([
            'cta_type' => 'course',
            'cta_label' => 'Xem khoá học',
            'cta_course_id' => $course->id,
            'content' => [
                ['type' => 'paragraph', 'data' => ['text' => 'Ưu đãi **lớn nhất** năm.']],
                ['type' => 'paragraph', 'data' => ['text' => '   ']],                       // trống: bỏ
                ['type' => 'steps', 'data' => ['title' => 'Cách áp dụng', 'items' => ['Mở app', '', 'Nhập mã']]],
                ['type' => 'bullets', 'data' => ['title' => 'Điều kiện', 'items' => [['item' => 'Học viên mới']]]],
                ['type' => 'promo_code', 'data' => ['promo_code_id' => $promo->id, 'note' => 'Nhập ở bước thanh toán']],
                ['type' => 'image', 'data' => ['image' => ['uuid' => 'campaigns/content/a.jpg'], 'caption' => 'Ảnh']],
                ['type' => 'callout', 'data' => ['tone' => 'warning', 'title' => 'Lưu ý', 'text' => 'Số lượng có hạn']],
                ['type' => 'unknown', 'data' => []],
            ],
        ]);

        $res = $this->getJson("/v1/campaigns/{$c->id}")->assertOk();

        $this->assertSame(['paragraph', 'steps', 'bullets', 'promo_code', 'image', 'callout'], array_column($res->json('data.blocks'), 'type'));
        $res->assertJsonPath('data.blocks.0.text', 'Ưu đãi **lớn nhất** năm.')
            ->assertJsonPath('data.blocks.1.items', ['Mở app', 'Nhập mã'])
            ->assertJsonPath('data.blocks.2.items', ['Học viên mới'])
            ->assertJsonPath('data.blocks.3.code', 'THANG11VIP')
            ->assertJsonPath('data.blocks.3.discount', '30% · tối đa 500.000' . "\u{00A0}₫")
            ->assertJsonPath('data.blocks.4.url', Storage::disk('public')->url('campaigns/content/a.jpg'))
            ->assertJsonPath('data.blocks.5.tone', 'warning')
            ->assertJsonPath('data.cta', ['type' => 'course', 'label' => 'Xem khoá học', 'course_id' => $course->id]);

        $hidden = $this->campaign(['is_active' => false]);
        $this->getJson("/v1/campaigns/{$hidden->id}")->assertNotFound();
    }

    public function test_cms_create_with_banner_validates_and_saves(): void
    {
        $this->actingAs($this->admin);
        $this->get(ListMarketingCampaigns::getUrl())->assertOk();
        $this->get(CreateMarketingCampaign::getUrl())->assertOk()->assertSee('1280 × 720');

        $undoBuilderFake = Builder::fake();

        // Sai tỉ lệ banner, nút "mở liên kết" thiếu chữ + link, kết thúc trước bắt đầu
        Livewire::test(CreateMarketingCampaign::class)
            ->fillForm([
                'title' => 'Sai',
                'banner_path' => UploadedFile::fake()->image('vuong.jpg', 800, 800),
                'starts_at' => '2026-11-10 08:00',
                'ends_at' => '2026-11-01 08:00',
                'cta_type' => 'url',
            ])
            ->call('create')
            ->assertHasFormErrors(['banner_path', 'ends_at', 'cta_label', 'cta_url']);

        $existing = $this->campaign(['sort_order' => 5]);

        Livewire::test(CreateMarketingCampaign::class)
            ->fillForm([
                'title' => 'Mừng khai trương',
                'summary' => 'Tặng 1 tháng',
                'banner_path' => UploadedFile::fake()->image('banner.jpg', 1280, 720),
                'starts_at' => '2026-11-01 00:00',
                'ends_at' => '2026-11-30 23:59',
                'is_active' => true,
                'cta_type' => 'url',
                'cta_label' => 'Tìm hiểu thêm',
                'cta_url' => 'https://ellso.edu.vn/khai-truong',
                'content' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Xin chào']],
                    ['type' => 'steps', 'data' => ['title' => 'Cách áp dụng', 'items' => [['item' => 'Đăng ký']]]],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $undoBuilderFake();

        $c = MarketingCampaign::where('title', 'Mừng khai trương')->firstOrFail();
        Storage::disk('public')->assertExists($c->banner_path);
        $this->assertStringStartsWith('campaigns/banners/', $c->banner_path);
        $this->assertSame(6, $c->sort_order); // xếp cuối dải banner
        $this->assertTrue($c->createdBy->is($this->admin));
        $this->assertSame('https://ellso.edu.vn/khai-truong', $c->cta_url);
        $this->assertNull($c->cta_course_id);
        $this->assertSame('2026-10-31 17:00:00', $c->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(['paragraph', 'steps'], array_column($c->content, 'type'));
    }

    public function test_cms_preview_toggle_and_replicate(): void
    {
        $this->actingAs($this->admin);
        $c = $this->campaign([
            'content' => [['type' => 'bullets', 'data' => ['title' => 'Điều kiện áp dụng', 'items' => ['Học viên mới']]]],
        ]);

        Livewire::test(EditMarketingCampaign::class, ['record' => $c->getRouteKey()])
            ->fillForm(['title' => 'Tiêu đề chưa lưu'])
            ->mountAction('preview')
            ->assertMountedActionModalSee(['Tiêu đề chưa lưu', 'Điều kiện áp dụng', 'Học viên mới', 'Khoá học của bạn']);

        Livewire::test(ListMarketingCampaigns::class)
            ->assertCanSeeTableRecords([$c])
            ->callAction(TestAction::make('toggleActive')->table($c));
        $this->assertFalse($c->fresh()->is_active);

        Livewire::test(ListMarketingCampaigns::class)
            ->callAction(TestAction::make('replicate')->table($c));
        $copy = MarketingCampaign::where('title', 'Ưu đãi tháng 11 (bản sao)')->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertSame($c->banner_path, $copy->banner_path);
    }

    public function test_instructor_cannot_manage_campaigns(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(ListMarketingCampaigns::getUrl())->assertForbidden();
    }
}

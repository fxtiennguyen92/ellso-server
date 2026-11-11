<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\PartnerEvents\Pages\CreatePartnerEvent;
use App\Filament\Resources\PartnerEvents\Pages\EditPartnerEvent;
use App\Filament\Resources\PartnerEvents\Pages\ListPartnerEvents;
use App\Models\PartnerEvent;
use App\Models\User;
use Database\Seeders\DemoPartnerEventSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Marketing › Sự kiện ngoài lề + API /v1/partner-events. */
class PartnerEventTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
    }

    private function event(array $attributes = []): PartnerEvent
    {
        Storage::disk('public')->put('partner-events/a.jpg', 'x');

        return PartnerEvent::create([
            'title' => 'Hội thảo AI',
            'image_path' => 'partner-events/a.jpg',
            'time_text' => '19:00 thứ Bảy, 15/11/2026',
            'location' => 'GEM Center, Quận 1',
            'description' => "Dòng 1\nDòng 2",
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(10),
            ...$attributes,
        ]);
    }

    public function test_api_lists_only_visible_events_in_order(): void
    {
        $second = $this->event(['title' => 'Thứ hai', 'sort_order' => 2]);
        $first = $this->event(['title' => 'Thứ nhất', 'sort_order' => 1]);
        $this->event(['title' => 'Đang ẩn', 'is_active' => false]);
        $this->event(['title' => 'Sắp tới', 'starts_at' => now()->addDay()]);
        $this->event(['title' => 'Đã hết', 'ends_at' => now()->subMinute()]);

        $this->getJson('/v1/partner-events')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id)
            ->assertJsonPath('data.0', [
                'id' => $first->id,
                'title' => 'Thứ nhất',
                'image' => ['url' => Storage::disk('public')->url('partner-events/a.jpg'), 'width' => 1280, 'height' => 720],
                'time_text' => '19:00 thứ Bảy, 15/11/2026',
                'location' => 'GEM Center, Quận 1',
                'description' => "Dòng 1\nDòng 2",
            ]);
    }

    public function test_api_detail_and_404(): void
    {
        $e = $this->event();
        $this->getJson("/v1/partner-events/{$e->id}")->assertOk()->assertJsonPath('data.title', 'Hội thảo AI');

        $hidden = $this->event(['is_active' => false]);
        $ended = $this->event(['ends_at' => now()->subMinute()]);
        $this->getJson("/v1/partner-events/{$hidden->id}")->assertNotFound();
        $this->getJson("/v1/partner-events/{$ended->id}")->assertNotFound();
        $this->getJson('/v1/partner-events/999999')->assertNotFound();
    }

    public function test_cms_create_validates_and_saves(): void
    {
        $this->actingAs($this->admin);
        $this->get(ListPartnerEvents::getUrl())->assertOk()->assertSee('Sự kiện ngoài lề');
        $this->get(CreatePartnerEvent::getUrl())->assertOk()->assertSee('1280 × 720');
        Storage::fake('public'); // request HTTP dựng lại disk public (UseRequestHostForPublicFiles) → fake lại

        // Sai tỉ lệ ảnh, thiếu trường bắt buộc, kết thúc trước bắt đầu
        Livewire::test(CreatePartnerEvent::class)
            ->fillForm([
                'title' => 'Sai',
                'image_path' => UploadedFile::fake()->image('vuong.jpg', 800, 800),
                'starts_at' => '2026-11-10 08:00',
                'ends_at' => '2026-11-01 08:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['image_path', 'ends_at', 'time_text', 'location', 'description']);

        $this->event(['sort_order' => 5]);

        Livewire::test(CreatePartnerEvent::class)
            ->fillForm([
                'title' => 'Ngày hội việc làm',
                'image_path' => UploadedFile::fake()->image('anh.jpg', 1280, 720),
                'time_text' => 'Chủ nhật 30/11/2026',
                'location' => 'Nhà văn hoá Thanh niên',
                'description' => "40 công ty tuyển dụng.\nMang theo CV.",
                'starts_at' => '2026-11-01 00:00',
                'ends_at' => '2026-11-30 23:59',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $e = PartnerEvent::where('title', 'Ngày hội việc làm')->firstOrFail();
        Storage::disk('public')->assertExists($e->image_path);
        $this->assertStringStartsWith('partner-events/', $e->image_path);
        $this->assertSame(6, $e->sort_order); // xếp cuối
        $this->assertTrue($e->createdBy->is($this->admin));
        $this->assertSame('2026-10-31 17:00:00', $e->starts_at->utc()->format('Y-m-d H:i:s')); // giờ VN
        $this->assertSame("40 công ty tuyển dụng.\nMang theo CV.", $e->description);
    }

    public function test_cms_toggle_replicate_reorder_and_image_cleanup(): void
    {
        $this->actingAs($this->admin);
        $e = $this->event();

        Livewire::test(ListPartnerEvents::class)
            ->assertCanSeeTableRecords([$e])
            ->assertSee('Đang hiển thị')
            ->callAction(TestAction::make('toggleActive')->table($e));
        $this->assertFalse($e->fresh()->is_active);

        Livewire::test(ListPartnerEvents::class)->callAction(TestAction::make('replicate')->table($e));
        $copy = PartnerEvent::where('title', 'Hội thảo AI (bản sao)')->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertSame($e->image_path, $copy->image_path);

        // Xoá bản gốc: ảnh còn được bản sao dùng → giữ; xoá nốt bản sao → xoá ảnh
        Livewire::test(EditPartnerEvent::class, ['record' => $e->getRouteKey()])->callAction('delete');
        $this->assertModelMissing($e);
        Storage::disk('public')->assertExists('partner-events/a.jpg');
        $copy->delete();
        Storage::disk('public')->assertMissing('partner-events/a.jpg');
    }

    public function test_instructor_cannot_manage_events(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(ListPartnerEvents::getUrl())->assertForbidden();
    }

    public function test_demo_seeder_creates_visible_events(): void
    {
        $this->seed(DemoPartnerEventSeeder::class);
        $this->seed(DemoPartnerEventSeeder::class); // chạy lại thay tin cũ

        $this->assertSame(4, PartnerEvent::count());
        Storage::disk('public')->assertExists(PartnerEvent::pluck('image_path')->all());
        $this->getJson('/v1/partner-events')->assertOk()->assertJsonCount(3, 'data'); // 1 tin sắp hiển thị
    }
}

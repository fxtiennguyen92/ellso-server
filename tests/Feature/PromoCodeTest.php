<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\DiscountType;
use App\Enums\PromoCodeStatus;
use App\Enums\UserRole;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Models\Category;
use App\Models\Course;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\PromoCodeException;
use App\Services\PromoCodeService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PromoCodeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Category $parentCategory;

    private Category $childCategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student]);
        $this->parentCategory = Category::create(['name' => 'Lãnh đạo']);
        $this->childCategory = Category::create(['name' => 'Lãnh đạo nhóm', 'parent_id' => $this->parentCategory->id]);
    }

    private function course(int $price, ?Category $category = null, CourseStatus $status = CourseStatus::Published): Course
    {
        return Course::create([
            'title' => 'Khoá ' . uniqid(),
            'instructor_id' => $this->admin->id,
            'category_id' => $category?->id,
            'price' => $price,
            'status' => $status,
        ]);
    }

    private function promo(array $attributes = []): PromoCode
    {
        return PromoCode::create([
            'code' => PromoCode::generateUniqueCode(),
            'name' => 'Test',
            'discount_type' => DiscountType::Percent,
            'discount_value' => 5,
            ...$attributes,
        ]);
    }

    private function evaluate(PromoCode $promo, Course ...$courses): array
    {
        return app(PromoCodeService::class)->evaluate($promo->code, $this->student, collect($courses));
    }

    private function assertRejected(string $reason, PromoCode $promo, Course ...$courses): void
    {
        try {
            $this->evaluate($promo, ...$courses);
            $this->fail("Expected rejection: {$reason}");
        } catch (PromoCodeException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    public function test_percent_with_cap_and_fixed_amount(): void
    {
        $course = $this->course(20_000_000);

        // 5% của 20tr = 1tr, trần 500k
        $result = $this->evaluate($this->promo(['max_discount' => 500_000]), $course);
        $this->assertSame(500_000, $result['discount']);
        $this->assertSame(19_500_000, $result['total']);

        $this->assertSame(1_000_000, $this->evaluate($this->promo(), $course)['discount']);

        // Giảm số tiền không vượt giá khoá
        $cheap = $this->course(150_000);
        $fixed = $this->promo(['discount_type' => DiscountType::Fixed, 'discount_value' => 200_000, 'max_discount' => 999]);
        $this->assertNull($fixed->max_discount); // trần bị bỏ với giảm theo số tiền
        $this->assertSame(150_000, $this->evaluate($fixed, $cheap)['discount']);

        // Làm tròn xuống đồng: 7.5% của 999.999
        $this->assertSame(74_999, $this->evaluate($this->promo(['discount_value' => 7.5]), $this->course(999_999))['discount']);
    }

    public function test_time_window_active_flag_and_min_order(): void
    {
        $course = $this->course(1_000_000);

        $this->assertRejected('not_started', $this->promo(['starts_at' => now()->addDay()]), $course);
        $this->assertRejected('expired', $this->promo(['ends_at' => now()->subMinute()]), $course);
        $this->assertRejected('inactive', $this->promo(['is_active' => false]), $course);
        $this->assertRejected('min_order_not_met', $this->promo(['min_order_amount' => 2_000_000]), $course);

        $ok = $this->promo(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'min_order_amount' => 1_000_000]);
        $this->assertSame(50_000, $this->evaluate($ok, $course)['discount']);

        $this->assertRejected('nothing_to_discount', $this->promo(), $this->course(0));
    }

    public function test_usage_limits_and_redeem(): void
    {
        $course = $this->course(1_000_000);
        $service = app(PromoCodeService::class);
        $promo = $this->promo(['usage_limit' => 2, 'per_user_limit' => 1]);

        $redemption = $service->redeem($promo->code, $this->student, collect([$course]), orderId: 42);
        $this->assertSame(50_000, $redemption->discount_amount);
        $this->assertSame(42, $redemption->order_id);
        $this->assertSame(1, $promo->fresh()->used_count);

        // Cùng học viên: hết lượt cá nhân
        $this->assertRejected('user_limit_reached', $promo->fresh(), $course);

        // Học viên khác dùng nốt lượt cuối → mã hết lượt
        $service->redeem($promo->code, User::factory()->create(), collect([$course]));
        $this->assertSame(PromoCodeStatus::UsedUp, $promo->fresh()->status());

        $this->expectException(PromoCodeException::class);
        $service->redeem($promo->code, User::factory()->create(), collect([$course]));
    }

    public function test_scope_courses_and_categories_including_children(): void
    {
        $picked = $this->course(1_000_000);
        $inChild = $this->course(2_000_000, $this->childCategory);
        $other = $this->course(3_000_000);

        $promo = $this->promo(['applies_to' => PromoCode::APPLIES_SELECTED]);
        $promo->courses()->attach($picked);
        $promo->categories()->attach($this->parentCategory);

        $this->assertSame(50_000, $this->evaluate($promo, $picked)['discount']);
        $this->assertSame(100_000, $this->evaluate($promo, $inChild)['discount']);
        $this->assertRejected('not_applicable', $promo, $other);

        // Đơn nhiều khoá: chỉ giảm trên khoá thuộc phạm vi
        $result = $this->evaluate($promo, $picked, $other);
        $this->assertSame(4_000_000, $result['subtotal']);
        $this->assertSame(50_000, $result['discount']);
        $this->assertSame([$picked->id], $result['eligible_course_ids']);
    }

    public function test_api_check(): void
    {
        $course = $this->course(20_000_000);
        $this->promo(['code' => 'KHAIGIANG5', 'max_discount' => 500_000, 'description' => 'Giảm 5%']);
        $token = $this->student->createToken('app')->plainTextToken;

        $this->withToken($token)->postJson('/v1/promo-codes/check', ['code' => ' khaigiang5 ', 'course_ids' => [$course->id]])
            ->assertOk()
            ->assertJson([
                'valid' => true,
                'code' => 'KHAIGIANG5',
                'subtotal' => 20_000_000,
                'discount' => 500_000,
                'total' => 19_500_000,
                'description' => 'Giảm 5%',
            ]);

        $this->withToken($token)->postJson('/v1/promo-codes/check', ['code' => 'KHONGCOMA', 'course_ids' => [$course->id]])
            ->assertStatus(422)
            ->assertJson(['valid' => false, 'reason' => 'not_found']);

        $draft = $this->course(1_000_000, status: CourseStatus::Draft);
        $this->withToken($token)->postJson('/v1/promo-codes/check', ['code' => 'KHAIGIANG5', 'course_ids' => [$draft->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('course_ids');

        $this->app['auth']->forgetGuards();
        $this->withoutToken()->postJson('/v1/promo-codes/check', ['code' => 'KHAIGIANG5', 'course_ids' => [$course->id]])->assertUnauthorized();

        // Chống dò mã: 10 lần / phút / người
        for ($i = 0; $i < 7; $i++) {
            $this->withToken($token)->postJson('/v1/promo-codes/check', ['code' => "SAI{$i}XXXXX", 'course_ids' => [$course->id]]);
        }
        $this->withToken($token)->postJson('/v1/promo-codes/check', ['code' => 'KHAIGIANG5', 'course_ids' => [$course->id]])
            ->assertStatus(429);
    }

    public function test_cms_create_validate_and_list(): void
    {
        $this->actingAs($this->admin);
        $course = $this->course(1_000_000);

        $this->get(ListPromoCodes::getUrl())->assertOk();
        $this->get(CreatePromoCode::getUrl())->assertOk();

        // Mã sai định dạng, % > 100, kết thúc trước bắt đầu, chọn phạm vi nhưng không chọn gì
        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'ab-12',
                'name' => 'Sai',
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => 150,
                'starts_at' => '2026-11-10 08:00',
                'ends_at' => '2026-11-01 08:00',
                'applies_to' => PromoCode::APPLIES_SELECTED,
            ])
            ->call('create')
            ->assertHasFormErrors(['code', 'discount_value', 'ends_at', 'courses']);

        Livewire::test(CreatePromoCode::class)
            ->fillForm([
                'code' => 'thang11vip',
                'name' => 'Khuyến mãi tháng 11',
                'discount_type' => DiscountType::Percent->value,
                'discount_value' => 5,
                'max_discount' => 500000,
                'min_order_amount' => 1000000,
                'starts_at' => '2026-11-01 00:00',
                'ends_at' => '2026-11-30 23:59',
                'usage_limit' => 100,
                'per_user_limit' => 1,
                'applies_to' => PromoCode::APPLIES_SELECTED,
                'courses' => [$course->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $promo = PromoCode::where('code', 'THANG11VIP')->firstOrFail();
        $this->assertSame(500_000, $promo->max_discount);
        $this->assertTrue($promo->courses->contains($course));
        $this->assertTrue($promo->createdBy->is($this->admin));
        // Giờ VN 00:00 01/11 = 17:00 31/10 UTC
        $this->assertSame('2026-10-31 17:00:00', $promo->starts_at->utc()->format('Y-m-d H:i:s'));

        // Trùng mã (kể cả đã xoá)
        $promo->delete();
        Livewire::test(CreatePromoCode::class)
            ->fillForm(['code' => 'THANG11VIP', 'name' => 'X', 'discount_type' => 'fixed', 'discount_value' => 1000, 'applies_to' => 'all'])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_cms_table_actions(): void
    {
        $this->actingAs($this->admin);
        $course = $this->course(1_000_000);

        $promo = $this->promo(['applies_to' => PromoCode::APPLIES_SELECTED, 'name' => 'Gốc']);
        $promo->courses()->attach($course);
        $expired = $this->promo(['ends_at' => now()->subDay()]);

        Livewire::test(ListPromoCodes::class)
            ->assertCanSeeTableRecords([$promo, $expired])
            ->filterTable('status', PromoCodeStatus::Expired->value)
            ->assertCanSeeTableRecords([$expired])
            ->assertCanNotSeeTableRecords([$promo]);

        Livewire::test(ListPromoCodes::class)
            ->callAction(TestAction::make('toggleActive')->table($promo));
        $this->assertFalse($promo->fresh()->is_active);

        Livewire::test(ListPromoCodes::class)
            ->callAction(TestAction::make('replicate')->table($promo), ['code' => 'BANSAO123', 'name' => 'Bản sao'])
            ->assertHasNoActionErrors();

        $copy = PromoCode::where('code', 'BANSAO123')->firstOrFail();
        $this->assertSame(0, $copy->used_count);
        $this->assertTrue($copy->is_active); // bản gốc đang tạm dừng, bản sao vẫn bật
        $this->assertTrue($copy->courses->contains($course));

        // Mã đã dùng thì không đổi được chuỗi mã
        app(PromoCodeService::class)->redeem($copy, $this->student, collect([$course]));
        Livewire::test(EditPromoCode::class, ['record' => $copy->getRouteKey()])
            ->fillForm(['code' => 'DOIMA12345', 'name' => 'Đổi tên'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('BANSAO123', $copy->fresh()->code);
        $this->assertSame('Đổi tên', $copy->fresh()->name);
    }

    public function test_instructor_cannot_manage_promo_codes(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(ListPromoCodes::getUrl())->assertForbidden();
    }
}

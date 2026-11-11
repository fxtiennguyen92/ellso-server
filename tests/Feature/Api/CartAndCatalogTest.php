<?php

namespace Tests\Feature\Api;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\DiscountType;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Course;
use App\Models\Document;
use App\Models\PlacementTest;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = User::factory()->create(['role' => UserRole::Student]);
        Sanctum::actingAs($this->student, ['app']);
    }

    private function course(array $attrs = []): Course
    {
        return Course::create([
            'title' => 'Khoá '.uniqid(),
            'category_id' => $attrs['category_id'] ?? Category::firstOrCreate(['name' => 'Chung'])->id,
            'instructor_id' => User::query()->where('role', UserRole::Instructor)->value('id')
                ?? User::factory()->create(['role' => UserRole::Instructor])->id,
            'type' => CourseType::Single,
            'status' => CourseStatus::Published,
            'price' => 500000,
            ...$attrs,
        ]);
    }

    private function book(array $attrs = []): Document
    {
        return Document::create([
            'type' => DocumentType::Book,
            'title' => 'Sách '.uniqid(),
            'author_name' => 'Tác giả',
            'price' => 120000,
            'status' => 'published',
            'published_at' => now(),
            ...$attrs,
        ]);
    }

    public function test_catalog_paginates_and_filters_by_category(): void
    {
        $a = Category::create(['name' => 'A', 'is_active' => true]);
        $child = Category::create(['name' => 'A con', 'parent_id' => $a->id, 'is_active' => true]);
        $b = Category::create(['name' => 'B', 'is_active' => true]);
        foreach (range(1, 12) as $i) {
            $this->course(['category_id' => $b->id]);
        }
        $this->course(['category_id' => $child->id]);
        $this->course(['category_id' => $a->id, 'status' => CourseStatus::Draft]);

        $this->getJson('/v1/courses')->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.total', 13);
        $this->getJson('/v1/courses?page=2')->assertJsonCount(3, 'data')->assertJsonPath('meta.has_more', false);
        $this->getJson('/v1/courses?category_id='.$a->id)->assertJsonCount(1, 'data');

        $names = collect($this->getJson('/v1/courses/categories')->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['A con', 'B'], $names);
    }

    public function test_cart_add_list_remove_course_and_book(): void
    {
        $course = $this->course(['price' => 300000]);
        $book = $this->book(['price' => 100000]);

        $this->postJson('/v1/cart', ['type' => 'course', 'id' => $course->id])->assertCreated()->assertJsonPath('data.count', 1);
        $this->postJson('/v1/cart', ['type' => 'document', 'id' => $book->id])->assertCreated();
        $this->postJson('/v1/cart', ['type' => 'document', 'id' => $book->id])->assertOk()->assertJsonPath('data.count', 2);

        $this->getJson('/v1/cart')->assertJsonPath('data.total', 400000)->assertJsonPath('data.items.0.kind', 'book');
        $this->getJson('/v1/cart')->assertJsonPath('data.coins.balance', 0)->assertJsonPath('data.coins.rate', 1000);
        $this->getJson('/v1/courses')->assertJsonPath('data.0.in_cart', true);

        $this->deleteJson("/v1/cart/course/{$course->id}")->assertOk()->assertJsonPath('data.count', 1);
    }

    public function test_cart_rejects_free_owned_or_unsold_items_and_drops_stale_ones(): void
    {
        $free = $this->course(['price' => 0]);
        $this->postJson('/v1/cart', ['type' => 'course', 'id' => $free->id])->assertStatus(422)->assertJsonPath('code', 'free');

        $pdf = $this->book(['type' => DocumentType::Pdf, 'price' => null]);
        $this->postJson('/v1/cart', ['type' => 'document', 'id' => $pdf->id])->assertStatus(422)->assertJsonPath('code', 'not_for_sale');

        $this->postJson('/v1/cart', ['type' => 'course', 'id' => 999])->assertNotFound();

        $course = $this->course();
        $this->postJson('/v1/cart', ['type' => 'course', 'id' => $course->id])->assertCreated();
        // Được cấp khoá trong lúc đang nằm trong giỏ → tự rời giỏ
        $this->student->enrollments()->create(['course_id' => $course->id, 'source' => 'admin', 'enrolled_at' => now()]);
        $this->getJson('/v1/cart')->assertJsonPath('data.count', 0);
        $this->assertDatabaseCount('cart_items', 0);
        $this->postJson('/v1/cart', ['type' => 'course', 'id' => $course->id])->assertStatus(422)->assertJsonPath('code', 'owned');
    }

    public function test_placement_tests_report_estimated_minutes(): void
    {
        $test = PlacementTest::create(['title' => 'Bài A', 'is_active' => true]);
        $this->getJson('/v1/placement-tests')->assertJsonPath('data.0.minutes', 3);

        $test->update(['duration_minutes' => 12]);
        $this->getJson('/v1/placement-tests')->assertJsonPath('data.0.minutes', 12);
    }

    public function test_cart_promo_preview_applies_to_courses_only(): void
    {
        $promo = PromoCode::create([
            'code' => PromoCode::generateUniqueCode(), 'name' => 'Giảm 10%',
            'discount_type' => DiscountType::Percent, 'discount_value' => 10, 'is_active' => true,
        ]);
        $book = $this->book(['price' => 100000]);
        $this->postJson('/v1/cart', ['type' => 'document', 'id' => $book->id]);
        $this->postJson('/v1/cart/promo', ['code' => $promo->code])->assertStatus(422)->assertJsonPath('reason', 'no_course');

        $course = $this->course(['price' => 500000]);
        $this->postJson('/v1/cart', ['type' => 'course', 'id' => $course->id]);
        $this->postJson('/v1/cart/promo', ['code' => $promo->code])->assertOk()
            ->assertJsonPath('data.discount', 50000)
            ->assertJsonPath('data.subtotal', 600000)
            ->assertJsonPath('data.total', 550000)
            ->assertJsonPath('data.eligible.0.id', $course->id);
        $this->postJson('/v1/cart/promo', ['code' => 'KHONGCO'])->assertStatus(422)->assertJsonPath('reason', 'not_found');
    }

    public function test_course_detail_returns_blocks_and_outline(): void
    {
        $course = $this->course([
            'short_description' => 'Ngắn gọn',
            'description' => '<h2>Bạn sẽ học</h2><p>Nói <strong>thuyết phục</strong> hơn.</p><ul><li>Mở đầu</li><li>Kết luận</li></ul>',
        ]);
        $res = $this->getJson("/v1/courses/{$course->id}")->assertOk();
        $res->assertJsonPath('data.title', $course->title)
            ->assertJsonPath('data.personalized', false)
            ->assertJsonPath('data.description_blocks.0', ['type' => 'heading', 'text' => 'Bạn sẽ học'])
            ->assertJsonPath('data.description_blocks.1.text', 'Nói **thuyết phục** hơn.')
            ->assertJsonPath('data.description_blocks.2.items', ['Mở đầu', 'Kết luận']);

        $draft = $this->course(['status' => CourseStatus::Draft]);
        $this->getJson("/v1/courses/{$draft->id}")->assertNotFound();
    }

    public function test_enroll_free_course_only(): void
    {
        $free = $this->course(['price' => 0]);
        $paid = $this->course(['price' => 100000]);
        $this->postJson("/v1/courses/{$paid->id}/enroll")->assertStatus(422)->assertJsonPath('code', 'not_free');
        $this->postJson("/v1/courses/{$free->id}/enroll")->assertCreated()->assertJsonPath('data.enrolled', true)->assertJsonPath('data.started', false);
        $this->postJson("/v1/courses/{$free->id}/enroll")->assertOk();
        $this->assertDatabaseHas('course_enrollments', ['user_id' => $this->student->id, 'course_id' => $free->id, 'source' => 'free']);
    }

    public function test_search_courses_and_documents_in_pages(): void
    {
        foreach (range(1, 9) as $i) {
            $this->course(['title' => "Giao tiếp phần {$i}"]);
        }
        $this->course(['title' => 'Marketing', 'short_description' => 'Kỹ năng giao tiếp với khách hàng']);
        $this->course(['title' => 'Excel nâng cao', 'category_id' => Category::create(['name' => 'Tin học văn phòng', 'is_active' => true])->id]);
        $this->getJson('/v1/courses?q=văn phòng')->assertJsonPath('data.0.title', 'Excel nâng cao');

        $this->getJson('/v1/courses?q=giao tiếp&per_page=7')->assertOk()
            ->assertJsonCount(7, 'data')->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.total', 10);
        $this->getJson('/v1/courses?q=giao tiếp&per_page=7&page=2')->assertJsonCount(3, 'data')->assertJsonPath('meta.has_more', false);

        foreach (range(1, 8) as $i) {
            $this->book(['title' => "Sổ tay giao tiếp {$i}"]);
        }
        $this->book(['title' => 'Khác hẳn']);
        $this->getJson('/v1/documents?q=giao tiếp&page=1&per_page=7')->assertOk()
            ->assertJsonCount(7, 'data')->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.total', 8);
        $this->getJson('/v1/documents?q=giao tiếp&page=2&per_page=7')->assertJsonCount(1, 'data');
        // Không có page → như cũ (danh sách, không meta)
        $this->getJson('/v1/documents')->assertJsonMissingPath('meta')->assertJsonCount(9, 'data');
    }
}

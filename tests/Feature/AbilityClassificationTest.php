<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\PlacementQuestionType;
use App\Enums\UserRole;
use App\Filament\Resources\Classifications\ClassificationResource;
use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Filament\Resources\PlacementTests\Pages\ManagePlacementDimensions;
use App\Models\Classification;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\PlacementAttempt;
use App\Models\PlacementDimension;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\PlacementScoring;
use App\Support\AbilityClassifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Phân loại "Năng lực" (từ bài kiểm tra đầu vào) dùng làm điều kiện lộ trình. */
class AbilityClassificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private PlacementTest $test;

    private PlacementDimension $dimension;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);

        // 1 câu chọn 1: A → 5 điểm (Xuất sắc), B → 1 điểm (Cần hỗ trợ)
        $this->test = PlacementTest::create(['title' => 'Đầu vào', 'is_active' => true]);
        $this->dimension = $this->test->dimensions()->create(['name' => 'Khả năng tiếp thu', 'sort_order' => 1]);
        $this->dimension->levels()->create(['name' => 'Cần hỗ trợ', 'min_score' => 0]);
        $this->dimension->levels()->create(['name' => 'Xuất sắc', 'min_score' => 4]);
        $q = $this->test->questions()->create(['type' => PlacementQuestionType::Single, 'prompt' => 'Câu 1']);
        $q->options()->create(['label' => 'A', 'scores' => [$this->dimension->id => 5]]);
        $q->options()->create(['label' => 'B', 'scores' => [$this->dimension->id => 1]]);
    }

    private function ability(): Classification
    {
        return Classification::where('type', Classification::TYPE_ABILITY)->sole()->load('options');
    }

    private function answer(string $label): PlacementAttempt
    {
        $test = $this->test->fresh()->loadStructure();
        $question = $test->questions->first();

        return app(PlacementScoring::class)->submit($this->student, $test, [
            ['question_id' => $question->id, 'option_ids' => [$question->options->firstWhere('label', $label)->id]],
        ]);
    }

    public function test_dimensions_sync_to_ability_classifications(): void
    {
        $ability = $this->ability();
        $this->assertSame('Khả năng tiếp thu', $ability->name);
        $this->assertSame('Năng lực · Khả năng tiếp thu', $ability->displayName());
        $this->assertNull($ability->course_id);
        $this->assertTrue($ability->is_active);
        $this->assertSame(['Xuất sắc', 'Cần hỗ trợ'], $ability->options->pluck('name')->all()); // cao → thấp

        // Đổi tên, thêm mức, xoá mức → đồng bộ (mức xoá chỉ ẩn để giữ điều kiện cũ)
        $this->dimension->update(['name' => 'Tiếp thu']);
        $this->dimension->levels()->create(['name' => 'Tốt', 'min_score' => 2]);
        $this->dimension->levels()->where('name', 'Cần hỗ trợ')->first()->delete();
        $ability = $this->ability();
        $this->assertSame('Tiếp thu', $ability->name);
        $this->assertSame(['Xuất sắc', 'Tốt'], $ability->options->where('is_active', true)->pluck('name')->values()->all());
        $this->assertFalse($ability->options->firstWhere('name', 'Cần hỗ trợ')->is_active);

        // Nhân bản bài → dùng chung key, không sinh năng lực mới; bật bản sao → vẫn là năng lực đó
        $copy = $this->test->duplicate();
        $this->assertSame(1, Classification::where('type', Classification::TYPE_ABILITY)->count());
        $copy->dimensions()->first()->update(['name' => 'Tiếp thu (mới)']);
        $this->assertSame('Tiếp thu', $this->ability()->name); // bài đang dùng vẫn là bài gốc
        $copy->update(['is_active' => true]);
        $this->assertSame('Tiếp thu (mới)', $this->ability()->name);
        $this->assertTrue($this->ability()->is_active);

        // Bài mới tạo từ đầu (không nhân bản) → năng lực cũ thành "không dùng"
        PlacementTest::create(['title' => 'Bài khác', 'is_active' => true])->dimensions()->create(['name' => 'Khác']);
        $this->assertFalse(Classification::where('placement_dimension_key', $this->dimension->key)->sole()->is_active);
        $this->assertTrue(Classification::where('name', 'Khác')->sole()->is_active);
    }

    public function test_suggest_path_uses_latest_placement_level(): void
    {
        $ability = $this->ability();
        $top = $ability->options->firstWhere('name', 'Xuất sắc');

        $goal = Classification::create(['name' => 'Mục tiêu', 'question' => 'Mục tiêu?']);
        $doIt = $goal->options()->create(['name' => 'Làm được']);

        $course = Course::create(['title' => 'Khoá A', 'instructor_id' => $this->admin->id, 'price' => 1, 'type' => CourseType::MultiPath, 'status' => CourseStatus::Published]);
        $course->classifications()->sync([$goal->id => ['sort_order' => 1], $ability->id => ['sort_order' => 2]]);
        $default = $course->defaultPath;
        $advanced = LearningPath::create(['course_id' => $course->id, 'name' => 'Nâng cao', 'sort_order' => 1]);
        $advanced->options()->sync([$doIt->id, $top->id]);           // Làm được + Xuất sắc
        $basic = LearningPath::create(['course_id' => $course->id, 'name' => 'Cơ bản', 'sort_order' => 2]);
        $basic->options()->sync([$doIt->id]);                        // Làm được, mọi mức

        // Chưa làm bài → điều kiện năng lực không khớp → lộ trình không đặt năng lực
        $this->assertSame([], AbilityClassifications::optionIdsFor($this->student));
        $this->assertTrue($course->suggestPathFor($this->student, [$doIt->id])->is($basic));

        // Đạt Xuất sắc → lộ trình khớp nhiều điều kiện nhất
        $this->answer('A');
        $this->assertSame([$top->id], AbilityClassifications::optionIdsFor($this->student));
        $this->assertTrue($course->suggestPathFor($this->student, [$doIt->id])->is($advanced));

        // Làm lại, mức thấp → dùng bài làm mới nhất
        $this->travel(1)->minutes();
        $this->answer('B');
        $this->assertTrue($course->suggestPathFor($this->student, [$doIt->id])->is($basic));
        $this->assertTrue($course->suggestPathFor($this->student, [])->is($default));

        // Bài làm cũ (trước khi có key) vẫn tra được mức theo level_id
        $attempt = PlacementAttempt::latest('id')->first();
        $attempt->update(['results' => collect($attempt->results)->map(fn ($r) => [...$r, 'level_key' => null, 'dimension_key' => null])->all()]);
        $this->assertSame(
            [$ability->options->firstWhere('name', 'Cần hỗ trợ')->id],
            AbilityClassifications::optionIdsFor($this->student),
        );
    }

    public function test_course_requires_placement_test_option(): void
    {
        $course = Course::create(['title' => 'Khoá B', 'instructor_id' => $this->admin->id, 'price' => 1, 'status' => CourseStatus::Published]);
        $this->assertFalse($course->needsPlacementTest($this->student));

        $course->update(['requires_placement_test' => true]);
        $this->assertTrue($course->fresh()->needsPlacementTest($this->student));

        $this->answer('A');
        $this->assertFalse($course->fresh()->needsPlacementTest($this->student));

        // Không có bài nào đang dùng → không chặn
        $other = User::factory()->create(['role' => UserRole::Student]);
        PlacementTest::query()->update(['is_active' => false]);
        $this->assertFalse($course->fresh()->needsPlacementTest($other));
    }

    public function test_cms_screens(): void
    {
        $this->actingAs($this->admin);
        $ability = $this->ability();
        $course = Course::create(['title' => 'Khoá C', 'instructor_id' => $this->admin->id, 'price' => 1, 'type' => CourseType::MultiPath, 'status' => CourseStatus::Draft]);
        $course->classifications()->sync([$ability->id => ['sort_order' => 1]]);

        $this->get(ClassificationResource::getUrl())->assertOk()->assertSee('Năng lực')->assertSee('Lấy tự động từ kết quả bài kiểm tra đầu vào');
        $this->get(ClassificationResource::getUrl('edit', ['record' => $ability]))->assertForbidden(); // sửa ở bài kiểm tra
        $this->get(ManagePlacementDimensions::getUrl(['record' => $this->test]))->assertOk()->assertSee('Dùng ở 1 khoá');
        $this->get(EditCourse::getUrl(['record' => $course]))->assertOk()->assertSee('Bắt buộc làm trước khi học');
        $this->get("/admin/courses/{$course->id}/learning-paths/{$course->defaultPath->id}/edit")
            ->assertOk()->assertSee('Năng lực · Khả năng tiếp thu')->assertSee('Theo mức đạt được ở bài kiểm tra đầu vào.');

        Livewire::test(EditCourse::class, ['record' => $course->getRouteKey()])
            ->fillForm(['requires_placement_test' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($course->fresh()->requires_placement_test);
    }
}

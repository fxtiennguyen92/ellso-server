<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\PlacementQuestionType;
use App\Enums\UserRole;
use App\Filament\Pages\Statistics\PlacementTestStatisticsPage;
use App\Filament\Resources\PlacementTests\Pages\EditPlacementTest;
use App\Filament\Resources\PlacementTests\Pages\ListPlacementTests;
use App\Filament\Resources\PlacementTests\Pages\ManagePlacementAttempts;
use App\Filament\Resources\PlacementTests\Pages\ManagePlacementDimensions;
use App\Filament\Resources\PlacementTests\Pages\ManagePlacementQuestions;
use App\Models\Course;
use App\Models\PlacementAttempt;
use App\Models\PlacementQuestion;
use App\Models\PlacementTest;
use App\Models\User;
use App\Services\PlacementScoring;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PlacementTestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private PlacementTest $test;

    /** @var array<string, int> */
    private array $dim = [];

    /** @var array<string, int> */
    private array $opt = [];

    /** @var array<string, int> */
    private array $q = [];

    private Course $course;

    private Course $draftCourse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
        $this->course = Course::create(['title' => 'Nâng cao', 'instructor_id' => $this->admin->id, 'price' => 1, 'status' => CourseStatus::Published]);
        $this->draftCourse = Course::create(['title' => 'Nháp', 'instructor_id' => $this->admin->id, 'price' => 1, 'status' => CourseStatus::Draft]);
        $this->buildTest();
    }

    /**
     * Đề mẫu: 2 phân loại (tiếp thu, kỷ luật), 3 câu (chọn 1, chọn nhiều ≤2, sắp xếp 3 mục trọng số mặc định 2/1/0).
     *   Tiếp thu: Xuất sắc ≥ 6, Tốt ≥ 3, Cần hỗ trợ ≥ 0.   Kỷ luật: Cao ≥ 4, Thấp ≥ 0.
     */
    private function buildTest(): void
    {
        $this->test = PlacementTest::create(['title' => 'Đầu vào', 'is_active' => true]);

        $tiepThu = $this->test->dimensions()->create(['name' => 'Tiếp thu', 'sort_order' => 1]);
        $kyLuat = $this->test->dimensions()->create(['name' => 'Kỷ luật', 'sort_order' => 2]);
        $this->dim = ['tt' => $tiepThu->id, 'kl' => $kyLuat->id];

        $top = $tiepThu->levels()->create(['name' => 'Xuất sắc', 'min_score' => 6, 'description' => 'Rất nhanh']);
        $top->courses()->sync([$this->course->id, $this->draftCourse->id]);
        $tiepThu->levels()->create(['name' => 'Tốt', 'min_score' => 3]);
        $tiepThu->levels()->create(['name' => 'Cần hỗ trợ', 'min_score' => 0]);
        $kyLuat->levels()->create(['name' => 'Cao', 'min_score' => 4]);
        $kyLuat->levels()->create(['name' => 'Thấp', 'min_score' => 0]);

        $make = function (string $key, PlacementQuestionType $type, array $options, array $extra = []) {
            $q = $this->test->questions()->create(['type' => $type, 'prompt' => "Câu {$key}", 'sort_order' => count($this->q) + 1, ...$extra]);
            $this->q[$key] = $q->id;
            foreach ($options as $i => [$name, $scores]) {
                $o = $q->options()->create(['label' => $name, 'scores' => $scores, 'sort_order' => $i]);
                $this->opt[$name] = $o->id;
            }
        };

        $tt = $this->dim['tt'];
        $kl = $this->dim['kl'];
        $make('single', PlacementQuestionType::Single, [
            ['A', [$tt => 3]],
            ['B', [$tt => 1, $kl => 1]],
        ]);
        $make('multi', PlacementQuestionType::Multiple, [
            ['M1', [$tt => 1]],
            ['M2', [$kl => 2]],
            ['M3', [$kl => -1]],
        ], ['max_choices' => 2]);
        $make('order', PlacementQuestionType::Ordering, [
            ['O1', [$tt => 1]],
            ['O2', [$kl => 1]],
            ['O3', []],
        ]);

        $this->test->loadStructure();
    }

    private function answers(array $single, array $multi, array $order): array
    {
        return [
            ['question_id' => $this->q['single'], 'option_ids' => array_map(fn ($k) => $this->opt[$k], $single)],
            ['question_id' => $this->q['multi'], 'option_ids' => array_map(fn ($k) => $this->opt[$k], $multi)],
            ['question_id' => $this->q['order'], 'option_ids' => array_map(fn ($k) => $this->opt[$k], $order)],
        ];
    }

    // ---------- Chấm điểm ----------

    public function test_scoring_totals_levels_and_ranges(): void
    {
        $scoring = app(PlacementScoring::class);

        // A(+3 tt) + M1(+1 tt) + O1 hạng 1 (×2 → +2 tt), O2 hạng 2 (×1 → +1 kl)
        $results = $scoring->results($this->test, $scoring->normalizeAnswers($this->test, $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3'])));
        $this->assertSame(6, $results[0]['score']);
        $this->assertSame('Xuất sắc', $results[0]['level']);
        $this->assertSame(1, $results[0]['rank']);
        $this->assertSame(1, $results[1]['score']);
        $this->assertSame('Thấp', $results[1]['level']);

        // B(+1 tt +1 kl) + M2,M3(+2−1 kl) + O2 hạng 1 (×2 kl), O1 hạng 3 (×0)
        $results = $scoring->results($this->test, $scoring->normalizeAnswers($this->test, $this->answers(['B'], ['M2', 'M3'], ['O2', 'O3', 'O1'])));
        $this->assertSame(1, $results[0]['score']);
        $this->assertSame('Cần hỗ trợ', $results[0]['level']);
        $this->assertSame(4, $results[1]['score']);
        $this->assertSame('Cao', $results[1]['level']);

        // Khoảng điểm: tiếp thu 1..6 (B, không M1 / A, M1, O1 hạng 1); kỷ luật −1..5 (A, M3 / B, M2, O2 hạng 1)
        $ranges = $scoring->ranges($this->test);
        $this->assertSame([1, 6], $ranges[$this->dim['tt']]);
        $this->assertSame([-1, 5], $ranges[$this->dim['kl']]);
    }

    public function test_custom_weights_and_level_fallback(): void
    {
        PlacementQuestion::find($this->q['order'])->update(['position_weights' => [5, null, 0]]);
        $this->test->refresh()->loadStructure();
        $scoring = app(PlacementScoring::class);

        $this->assertSame([5, 1, 0], $this->test->questions[2]->weights());
        $results = $scoring->results($this->test, $scoring->normalizeAnswers($this->test, $this->answers(['B'], ['M1'], ['O1', 'O2', 'O3'])));
        $this->assertSame(7, $results[0]['score']); // 1 + 1 + 5

        // Điểm thấp hơn mọi mức → mức thấp nhất
        $this->test->dimensions[1]->levels()->where('name', 'Thấp')->update(['min_score' => 3]);
        $this->test->refresh()->loadStructure();
        $results = $scoring->results($this->test, $scoring->normalizeAnswers($this->test, $this->answers(['A'], ['M3'], ['O1', 'O3', 'O2'])));
        $this->assertSame(-1, $results[1]['score']);
        $this->assertSame('Thấp', $results[1]['level']);
    }

    public function test_answer_validation(): void
    {
        $scoring = app(PlacementScoring::class);
        $cases = [
            [$this->answers(['A', 'B'], ['M1'], ['O1', 'O2', 'O3']), $this->q['single'], 'chỉ chọn 1'],
            [$this->answers(['A'], ['M1', 'M2', 'M3'], ['O1', 'O2', 'O3']), $this->q['multi'], 'từ 1 đến 2'],
            [$this->answers(['A'], ['M1'], ['O1', 'O2']), $this->q['order'], 'đủ tất cả'],
            [$this->answers(['A'], ['M1'], ['O1', 'O1', 'O2']), $this->q['order'], 'không hợp lệ'],
            [$this->answers(['M1'], ['M1'], ['O1', 'O2', 'O3']), $this->q['single'], 'không hợp lệ'],
            [array_slice($this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']), 0, 2), $this->q['order'], 'chưa trả lời'],
        ];

        foreach ($cases as [$answers, $questionId, $message]) {
            try {
                $scoring->normalizeAnswers($this->test, $answers);
                $this->fail("Phải báo lỗi: {$message}");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($message, $e->errors()["answers.{$questionId}"][0]);
            }
        }
    }

    // ---------- API ----------

    public function test_api_show_submit_and_result(): void
    {
        $this->getJson('/v1/placement-test')->assertUnauthorized();
        $token = $this->student->createToken('app')->plainTextToken;

        $show = $this->withToken($token)->getJson('/v1/placement-test')->assertOk()
            ->assertJsonPath('data.id', $this->test->id)
            ->assertJsonPath('data.completed', false)
            ->assertJsonPath('data.questions.1.type', 'multiple')
            ->assertJsonPath('data.questions.1.max_choices', 2)
            ->assertJsonPath('data.questions.2.options.0.label', 'O1');
        $this->assertStringNotContainsString('scores', $show->getContent()); // không lộ cách chấm

        $this->withToken($token)->getJson('/v1/placement-test/result')->assertOk()->assertJsonPath('data', null);

        $this->withToken($token)->postJson('/v1/placement-test/attempts', [
            'test_id' => $this->test->id,
            'answers' => $this->answers(['A'], ['M1', 'M2', 'M3'], ['O1', 'O2', 'O3']),
        ])->assertUnprocessable()->assertJsonValidationErrors(["answers.{$this->q['multi']}"]);

        $this->withToken($token)->postJson('/v1/placement-test/attempts', [
            'test_id' => $this->test->id,
            'answers' => $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']),
        ])->assertCreated()
            ->assertJsonPath('data.dimensions.0.name', 'Tiếp thu')
            ->assertJsonPath('data.dimensions.0.score', 6)
            ->assertJsonPath('data.dimensions.0.max_score', 6)
            ->assertJsonPath('data.dimensions.0.level.name', 'Xuất sắc')
            ->assertJsonPath('data.dimensions.0.level.description', 'Rất nhanh')
            ->assertJsonPath('data.dimensions.0.level.rank', 1)
            ->assertJsonPath('data.dimensions.0.level.level_count', 3)
            ->assertJsonPath('data.dimensions.1.level.name', 'Thấp')
            ->assertJsonPath('data.recommended_courses', [[
                'id' => $this->course->id, 'title' => 'Nâng cao', 'short_description' => null, 'cover_url' => null,
            ]]); // khoá nháp không được gợi ý

        $attempt = PlacementAttempt::sole();
        $this->assertSame(['O1', 'O2', 'O3'], $attempt->answers[2]['labels']);

        $this->withToken($token)->getJson('/v1/placement-test')->assertJsonPath('data.completed', true);
        $this->withToken($token)->getJson('/v1/placement-test/result')->assertJsonPath('data.id', $attempt->id);

        // Admin đổi bài đang dùng trong lúc học viên làm bài
        PlacementTest::create(['title' => 'Bài mới', 'is_active' => true]);
        $this->withToken($token)->postJson('/v1/placement-test/attempts', [
            'test_id' => $this->test->id,
            'answers' => $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']),
        ])->assertStatus(409)->assertJsonPath('code', 'test_changed');

        PlacementTest::query()->update(['is_active' => false]);
        $this->withToken($token)->getJson('/v1/placement-test')->assertOk()->assertJsonPath('data', null);
    }

    // ---------- CMS ----------

    public function test_cms_pages_render_and_instructor_forbidden(): void
    {
        $this->actingAs($this->admin);
        app(PlacementScoring::class)->submit($this->student, $this->test, $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']));

        $this->get(ListPlacementTests::getUrl())->assertOk()->assertSee('Đầu vào');
        $this->get(ManagePlacementQuestions::getUrl(['record' => $this->test]))->assertOk()->assertSee('Câu order')->assertSee('Sắp xếp');
        $this->get(ManagePlacementDimensions::getUrl(['record' => $this->test]))->assertOk()->assertSee('Xuất sắc')->assertSee('1 → 6');
        $this->get(ManagePlacementAttempts::getUrl(['record' => $this->test]))->assertOk()->assertSee('Tiếp thu: <b>Xuất sắc</b>', false);
        $this->get(EditPlacementTest::getUrl(['record' => $this->test]))->assertOk();
        $this->get(PlacementTestStatisticsPage::getUrl())->assertOk()->assertSee('Kỷ luật');

        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(ListPlacementTests::getUrl())->assertForbidden();
    }

    public function test_cms_create_question_with_scores_and_weights(): void
    {
        $this->actingAs($this->admin);
        $undo = Repeater::fake();

        Livewire::test(ManagePlacementQuestions::class, ['record' => $this->test->getRouteKey()])
            ->callAction(TestAction::make('create')->table(), [
                'type' => PlacementQuestionType::Ordering->value,
                'prompt' => 'Xếp ưu tiên',
                'options' => [
                    ['label' => 'Video', 'scores' => [$this->dim['tt'] => 2, $this->dim['kl'] => null]],
                    ['label' => 'Sách', 'scores' => [$this->dim['kl'] => '-1']],
                ],
                'position_weights' => [0 => '4', 1 => null],
                'min_choices' => 2,
            ])
            ->assertHasNoFormErrors();

        $undo();

        $q = PlacementQuestion::where('prompt', 'Xếp ưu tiên')->sole();
        $this->assertSame(PlacementQuestionType::Ordering, $q->type);
        $this->assertSame(4, $q->sort_order);
        $this->assertNull($q->min_choices); // không phải câu chọn nhiều
        $this->assertSame([4, 0], $q->weights());
        $this->assertSame(['Video', 'Sách'], $q->options->pluck('label')->all());
        $this->assertSame(2, $q->options[0]->scoreFor($this->dim['tt']));
        $this->assertSame(-1, $q->options[1]->scoreFor($this->dim['kl']));
    }

    public function test_cms_create_dimension_with_levels(): void
    {
        $this->actingAs($this->admin);
        $undo = Repeater::fake();

        Livewire::test(ManagePlacementDimensions::class, ['record' => $this->test->getRouteKey()])
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Kinh nghiệm',
                'levels' => [
                    ['name' => 'Chuyên sâu', 'min_score' => 5, 'description' => 'Giỏi', 'courses' => [$this->course->id]],
                    ['name' => 'Mới bắt đầu', 'min_score' => 0],
                ],
            ])
            ->assertHasNoFormErrors();

        $undo();

        $dimension = $this->test->dimensions()->where('name', 'Kinh nghiệm')->sole();
        $this->assertSame(3, $dimension->sort_order);
        $this->assertSame(['Chuyên sâu', 'Mới bắt đầu'], $dimension->levels->pluck('name')->all());
        $this->assertSame([$this->course->id], $dimension->levels[0]->courses->pluck('id')->all());
    }

    public function test_cms_simulate_activate_duplicate_and_delete_guard(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManagePlacementQuestions::class, ['record' => $this->test->getRouteKey()])
            ->mountAction('simulate')
            ->fillForm([
                'q' . $this->q['single'] => $this->opt['A'],
                'q' . $this->q['multi'] => [$this->opt['M1']],
                'q' . $this->q['order'] => [['id' => $this->opt['O1']], ['id' => $this->opt['O2']], ['id' => $this->opt['O3']]],
            ])
            ->callMountedAction()
            ->assertMountedActionModalSee(['Xuất sắc', 'Rất nhanh', 'Nâng cao', '6 điểm']);
        $this->assertSame(0, PlacementAttempt::count()); // làm thử không lưu

        // Bật bài khác → bài này tắt
        $other = PlacementTest::create(['title' => 'Khác']);
        Livewire::test(ListPlacementTests::class)->callAction(TestAction::make('activate')->table($other));
        $this->assertTrue($other->fresh()->is_active);
        $this->assertFalse($this->test->fresh()->is_active);

        // Nhân bản: điểm gắn sang phân loại mới
        Livewire::test(ListPlacementTests::class)->callAction(TestAction::make('duplicate')->table($this->test));
        $copy = PlacementTest::where('title', 'Đầu vào (bản sao)')->sole()->loadStructure();
        $this->assertFalse($copy->is_active);
        $this->assertCount(3, $copy->questions);
        $newTt = $copy->dimensions->firstWhere('name', 'Tiếp thu');
        $this->assertNotSame($this->dim['tt'], $newTt->id);
        $this->assertSame(3, $copy->questions[0]->options[0]->scoreFor($newTt->id));
        $this->assertSame([$this->course->id, $this->draftCourse->id], $newTt->levels->first()->courses->pluck('id')->sort()->values()->all());

        // Đã có bài làm → không xoá được
        app(PlacementScoring::class)->submit($this->student, $this->test, $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']));
        Livewire::test(ListPlacementTests::class)
            ->assertActionHidden(TestAction::make('delete')->table($this->test))
            ->assertActionVisible(TestAction::make('delete')->table($copy));
    }
}

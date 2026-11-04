<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\PlacementQuestionType;
use App\Enums\UserRole;
use App\Filament\Pages\Statistics\PlacementTestStatisticsPage;
use App\Filament\Resources\PlacementAttempts\Pages\ListPlacementAttempts;
use App\Filament\Resources\PlacementAttempts\PlacementAttemptResource;
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
use App\Support\PlacementStatistics;
use App\Support\SeenMarker;
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
     *   Ngưỡng theo %: Tiếp thu: Xuất sắc ≥ 80%, Tốt ≥ 40%, Cần hỗ trợ ≥ 0%.   Kỷ luật: Cao ≥ 70%, Thấp ≥ 0%.
     */
    private function buildTest(): void
    {
        // Không đảo đáp án để kiểm tra theo thứ tự soạn (đảo / rút câu: test_random_draw_*)
        $this->test = PlacementTest::create(['title' => 'Đầu vào', 'is_active' => true, 'shuffle_options' => false]);

        $tiepThu = $this->test->dimensions()->create(['name' => 'Tiếp thu', 'sort_order' => 1]);
        $kyLuat = $this->test->dimensions()->create(['name' => 'Kỷ luật', 'sort_order' => 2]);
        $this->dim = ['tt' => $tiepThu->id, 'kl' => $kyLuat->id];

        $top = $tiepThu->levels()->create(['name' => 'Xuất sắc', 'min_percent' => 80, 'description' => 'Rất nhanh']);
        $top->courses()->sync([$this->course->id, $this->draftCourse->id]);
        $tiepThu->levels()->create(['name' => 'Tốt', 'min_percent' => 40]);
        $tiepThu->levels()->create(['name' => 'Cần hỗ trợ', 'min_percent' => 0]);
        $kyLuat->levels()->create(['name' => 'Cao', 'min_percent' => 70]);
        $kyLuat->levels()->create(['name' => 'Thấp', 'min_percent' => 0]);

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
        $this->assertSame(100.0, $results[0]['percent']); // khoảng 1..6
        $this->assertSame('Xuất sắc', $results[0]['level']);
        $this->assertSame(1, $results[0]['rank']);
        $this->assertSame(1, $results[1]['score']);
        $this->assertSame(33.3, $results[1]['percent']); // khoảng −1..5
        $this->assertSame('Thấp', $results[1]['level']);

        // B(+1 tt +1 kl) + M2,M3(+2−1 kl) + O2 hạng 1 (×2 kl), O1 hạng 3 (×0)
        $results = $scoring->results($this->test, $scoring->normalizeAnswers($this->test, $this->answers(['B'], ['M2', 'M3'], ['O2', 'O3', 'O1'])));
        $this->assertSame(1, $results[0]['score']);
        $this->assertSame('Cần hỗ trợ', $results[0]['level']);
        $this->assertSame(4, $results[1]['score']);
        $this->assertSame(83.3, $results[1]['percent']);
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
        $this->test->dimensions[1]->levels()->where('name', 'Thấp')->update(['min_percent' => 3]);
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

        // Bài khác chạy song song → bài này vẫn nộp được
        $other = PlacementTest::create(['title' => 'Bài mới', 'is_active' => true]);
        $this->withToken($token)->postJson('/v1/placement-test/attempts', [
            'test_id' => $this->test->id,
            'answers' => $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']),
        ])->assertCreated();

        // Admin bật phiên bản mới trong lúc học viên làm bài → 409 kèm id phiên bản mới
        $v2 = $this->test->duplicate();
        $v2->update(['is_active' => true]);
        $this->withToken($token)->postJson('/v1/placement-test/attempts', [
            'test_id' => $this->test->id,
            'answers' => $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']),
        ])->assertStatus(409)->assertJsonPath('code', 'test_changed')->assertJsonPath('replacement_test_id', $v2->id);

        PlacementTest::query()->update(['is_active' => false]);
        $this->withToken($token)->getJson('/v1/placement-test')->assertOk()->assertJsonPath('data', null);
    }

    public function test_api_multiple_tests(): void
    {
        $this->getJson('/v1/placement-tests')->assertUnauthorized();
        $token = $this->student->createToken('app')->plainTextToken;

        $second = PlacementTest::create(['title' => 'Tư duy', 'intro' => 'Bài 2', 'is_active' => true]);
        $dim = $second->dimensions()->create(['name' => 'Logic']);
        $dim->levels()->create(['name' => 'Tốt', 'min_percent' => 0]);
        $q = $second->questions()->create(['type' => PlacementQuestionType::Single, 'prompt' => 'Câu 1']);
        $x = $q->options()->create(['label' => 'X', 'scores' => [$dim->id => 1]]);
        $q->options()->create(['label' => 'Y', 'scores' => []]);
        PlacementTest::create(['title' => 'Đang tắt']);

        // Danh sách: chỉ bài đang dùng, theo thứ tự
        $second->update(['sort_order' => 0]);
        $this->withToken($token)->getJson('/v1/placement-tests')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.question_count', 1)
            ->assertJsonPath('data.0.completed', false)
            ->assertJsonPath('data.1.id', $this->test->id);

        $this->withToken($token)->getJson("/v1/placement-tests/{$second->id}")->assertOk()
            ->assertJsonPath('data.title', 'Tư duy')
            ->assertJsonPath('data.questions.0.options.0.label', 'X');
        $this->withToken($token)->getJson("/v1/placement-tests/{$second->id}/result")->assertOk()->assertJsonPath('data', null);

        $this->withToken($token)->postJson("/v1/placement-tests/{$second->id}/attempts", [
            'answers' => [['question_id' => $q->id, 'option_ids' => [$x->id]]],
        ])->assertCreated()->assertJsonPath('data.test_title', 'Tư duy')->assertJsonPath('data.dimensions.0.level.name', 'Tốt');

        $this->withToken($token)->getJson('/v1/placement-tests')
            ->assertJsonPath('data.0.completed', true)
            ->assertJsonPath('data.1.completed', false);
        $this->withToken($token)->getJson("/v1/placement-tests/{$this->test->id}/result")->assertJsonPath('data', null);

        // Phiên bản mới: id cũ → 404 kèm id mới; kết quả / đã làm tính theo bài
        $v2 = $second->duplicate();
        $v2->update(['is_active' => true]);
        $this->withToken($token)->getJson("/v1/placement-tests/{$second->id}")->assertNotFound()
            ->assertJsonPath('replacement_test_id', $v2->id);
        $this->withToken($token)->getJson("/v1/placement-tests/{$v2->id}")->assertOk()->assertJsonPath('data.completed', true);
        $this->withToken($token)->getJson("/v1/placement-tests/{$v2->id}/result")->assertJsonPath('data.test_id', $second->id);
    }

    // ---------- Rút câu ngẫu nhiên ----------

    /** Ngân hàng 12 câu chọn 1: 6 câu chỉ tính Tiếp thu, 6 câu chỉ tính Kỷ luật. */
    private function bank(): void
    {
        foreach (range(1, 12) as $i) {
            $dim = $i <= 6 ? $this->dim['tt'] : $this->dim['kl'];
            $q = $this->test->questions()->create(['type' => PlacementQuestionType::Single, 'prompt' => "Ngân hàng {$i}", 'sort_order' => 10 + $i]);
            foreach (['Cao' => 4, 'Giữa' => 2, 'Thấp' => 0, 'Không' => 0] as $label => $points) {
                $q->options()->create(['label' => "{$i}-{$label}", 'scores' => [$dim => $points]]);
            }
        }
    }

    public function test_random_draw_covers_every_dimension_and_is_stable_until_submit(): void
    {
        $this->bank(); // 15 câu
        $this->test->update(['question_count' => 2, 'shuffle_options' => true]);
        $test = $this->test->fresh()->loadStructure();
        $this->assertSame(2, $test->questionsPerAttempt());

        // Mọi seed: 2 câu, luôn có ít nhất 1 câu cho mỗi năng lực
        $draws = [];
        foreach (range(1, 40) as $seed) {
            $drawn = $test->draw($seed);
            $this->assertCount(2, $drawn);
            foreach ($this->dim as $dimId) {
                $this->assertTrue($drawn->contains(fn ($q) => $q->options->contains(fn ($o) => $o->scoreFor($dimId) !== 0)));
            }
            $draws[] = $drawn->pluck('id')->sort()->join(',');
        }
        $this->assertGreaterThan(5, count(array_unique($draws))); // thật sự ngẫu nhiên

        // Cùng seed → cùng câu, cùng thứ tự đáp án; seed khác → thứ tự đáp án khác (ít nhất ở một lần)
        $this->assertSame($test->draw(7)->map(fn ($q) => $q->options->pluck('id')->all())->all(), $test->draw(7)->map(fn ($q) => $q->options->pluck('id')->all())->all());
        $orders = collect(range(1, 20))->map(fn ($seed) => $test->draw($seed)->first()->options->pluck('id')->join(','));
        $this->assertGreaterThan(1, $orders->unique()->count());
        // Đáp án chỉ đổi thứ tự, không mất / thêm
        $first = $test->draw(3)->first();
        $this->assertEqualsCanonicalizing($test->questions->firstWhere('id', $first->id)->options->pluck('id')->all(), $first->options->pluck('id')->all());

        // API: tải lại vẫn ra đúng bộ câu đó
        $token = $this->student->createToken('app')->plainTextToken;
        $show = $this->withToken($token)->getJson("/v1/placement-tests/{$test->id}")->assertOk()->assertJsonCount(2, 'data.questions')
            ->assertJsonPath('data.question_count', 2);
        $again = $this->withToken($token)->getJson("/v1/placement-tests/{$test->id}")->json('data.questions');
        $this->assertSame($show->json('data.questions'), $again);
        $this->withToken($token)->getJson('/v1/placement-tests')->assertJsonPath('data.0.question_count', 2);

        // Nộp câu không thuộc bộ câu → 409 (app tải lại đề)
        $drawnIds = array_column($show->json('data.questions'), 'id');
        $other = $test->questions->first(fn ($q) => ! in_array($q->id, $drawnIds, true));
        $this->withToken($token)->postJson("/v1/placement-tests/{$test->id}/attempts", [
            'answers' => [['question_id' => $other->id, 'option_ids' => [$other->options->first()->id]]],
        ])->assertStatus(409)->assertJsonPath('code', 'test_changed');

        // Thiếu câu → 422 theo đúng câu
        $this->withToken($token)->postJson("/v1/placement-tests/{$test->id}/attempts", [
            'answers' => [['question_id' => $drawnIds[0], 'option_ids' => [$show->json('data.questions.0.options.0.id')]]],
        ])->assertUnprocessable()->assertJsonValidationErrors(["answers.{$drawnIds[1]}"]);

        // Chọn đáp án điểm cao nhất ở mọi câu → 100% mọi năng lực, dù chỉ làm 2 câu
        $best = collect($show->json('data.questions'))->map(function ($q) use ($test) {
            $question = $test->questions->firstWhere('id', $q['id']);
            $top = $question->options->sortByDesc(fn ($o) => array_sum($o->scores ?? []))->first();

            return ['question_id' => $q['id'], 'option_ids' => [$top->id]];
        })->all();
        $this->withToken($token)->postJson("/v1/placement-tests/{$test->id}/attempts", ['answers' => $best])->assertCreated()
            ->assertJsonPath('data.dimensions.0.percent', 100)
            ->assertJsonPath('data.dimensions.0.level.name', 'Xuất sắc')
            ->assertJsonPath('data.dimensions.0.max_score', 4)
            ->assertJsonPath('data.dimensions.1.percent', 100)
            ->assertJsonPath('data.dimensions.1.level.name', 'Cao');
        $this->assertCount(2, PlacementAttempt::sole()->answers); // bản chụp chỉ gồm câu đã làm

        // Đã nộp → lần làm sau ra bộ câu mới (seed theo số lần làm)
        $this->assertNotSame($test->seedFor($this->student->fresh()), crc32(config('app.key') . "|placement|{$test->id}|{$this->student->id}|0"));
    }

    public function test_random_draw_without_count_keeps_all_questions_in_order(): void
    {
        $test = $this->test->fresh()->loadStructure();
        $this->assertFalse($test->drawsQuestions());
        $this->assertSame($test->questions->pluck('id')->all(), $test->draw(123)->pluck('id')->all());
        $this->assertSame($test->questions[0]->options->pluck('id')->all(), $test->draw(123)[0]->options->pluck('id')->all()); // shuffle_options = false

        // Số câu lớn hơn ngân hàng → làm hết (thứ tự ngẫu nhiên)
        $test->update(['question_count' => 50]);
        $this->assertSame(3, $test->fresh()->questionsPerAttempt());
        $this->assertEqualsCanonicalizing($test->questions->pluck('id')->all(), $test->fresh()->draw(5)->pluck('id')->all());
    }

    public function test_cms_settings_and_simulate_random_draw(): void
    {
        $this->actingAs($this->admin);
        $this->bank();

        $this->test->update(['question_count' => 4]);
        $this->get(ManagePlacementQuestions::getUrl(['record' => $this->test]))->assertOk()
            ->assertSee('ngân hàng 15 câu, mỗi lần làm rút ngẫu nhiên 4 câu');
        Livewire::test(ListPlacementTests::class)->assertSee('4 / 15');

        // Làm thử: modal chỉ có 4 câu
        $page = Livewire::test(ManagePlacementQuestions::class, ['record' => $this->test->getRouteKey()])->mountAction('simulate');
        $seed = $page->get('simulationSeed');
        $this->assertNotNull($seed);
        $drawn = $this->test->fresh()->loadStructure()->draw($seed);
        $this->assertCount(4, $drawn);
        $fields = [];
        foreach ($drawn as $q) {
            $fields['q' . $q->id] = match ($q->type) {
                PlacementQuestionType::Single => $q->options->first()->id,
                PlacementQuestionType::Multiple => [$q->options->first()->id],
                PlacementQuestionType::Ordering => $q->options->map(fn ($o) => ['id' => $o->id])->values()->all(),
            };
        }
        $page->fillForm($fields)->callMountedAction()->assertMountedActionModalSee('%');
        $this->assertSame(0, PlacementAttempt::count());

        // Form Thông tin: đặt số câu, đảo đáp án
        Livewire::test(\App\Filament\Resources\PlacementTests\Pages\EditPlacementTest::class, ['record' => $this->test->getRouteKey()])
            ->assertSee('Số câu mỗi lần làm')
            ->fillForm(['question_count' => 6, 'shuffle_options' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(6, $this->test->fresh()->question_count);

    }

    // ---------- CMS ----------

    public function test_statistics_merge_versions_by_test_key(): void
    {
        $scoring = app(PlacementScoring::class);
        // Bản cũ: học viên 1 đạt Xuất sắc
        $scoring->submit($this->student, $this->test, $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']));

        // Bản nháp được dùng thay bản cũ; học viên 2 làm bản mới
        $v2 = $this->test->duplicate();
        $v2->update(['is_active' => true]);
        $other = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
        $v2 = $v2->fresh()->loadStructure();
        $q = $v2->questions->values();
        $answers = [
            ['question_id' => $q[0]->id, 'option_ids' => [$q[0]->options->firstWhere('label', 'B')->id]],
            ['question_id' => $q[1]->id, 'option_ids' => [$q[1]->options->firstWhere('label', 'M3')->id]],
            ['question_id' => $q[2]->id, 'option_ids' => $q[2]->options->pluck('id')->all()],
        ];
        $scoring->submit($other, $v2, $answers);

        $filters = PlacementStatistics::defaultFilters();
        $this->assertSame($this->test->key, $filters['test_key']);
        $stats = new PlacementStatistics($filters);
        $this->assertSame($v2->id, $stats->test()->id); // tên mức theo bản đang dùng
        $this->assertSame(2, $stats->summary()['attempts']); // gộp cả 2 phiên bản

        $dist = collect($stats->distribution($this->test->dimensions()->where('id', $this->dim['tt'])->value('key')))->pluck('count', 'name')->filter()->all();
        $this->assertSame(2, array_sum($dist));
        $this->assertArrayHasKey('Xuất sắc', $dist);
        $this->assertNotNull($stats->averagePercent($this->test->dimensions()->where('id', $this->dim['tt'])->value('key')));

        // Danh sách bài trong bộ lọc: 1 dòng cho cả 2 phiên bản
        $this->assertCount(1, PlacementTest::keyOptions());
    }

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
                    ['name' => 'Chuyên sâu', 'min_percent' => 5, 'description' => 'Giỏi', 'courses' => [$this->course->id]],
                    ['name' => 'Mới bắt đầu', 'min_percent' => 0],
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

        // Bật bài khác → chạy song song, bài này vẫn dùng
        $other = PlacementTest::create(['title' => 'Khác']);
        Livewire::test(ListPlacementTests::class)->callAction(TestAction::make('activate')->table($other));
        $this->assertTrue($other->fresh()->is_active);
        $this->assertTrue($this->test->fresh()->is_active);

        // Ngừng dùng
        Livewire::test(ListPlacementTests::class)->callAction(TestAction::make('deactivate')->table($other));
        $this->assertFalse($other->fresh()->is_active);

        // Nhân bản thành bài mới → key khác
        Livewire::test(ListPlacementTests::class)->callAction(TestAction::make('duplicate')->table($other), ['mode' => 'new']);
        $this->assertNotSame($other->key, PlacementTest::where('title', 'Khác (bản sao)')->sole()->key);

        // Nhân bản thành phiên bản mới (mặc định): điểm gắn sang phân loại mới, cùng key bài
        Livewire::test(ListPlacementTests::class)->callAction(TestAction::make('duplicate')->table($this->test));
        $copy = PlacementTest::where('title', 'Đầu vào (bản sao)')->sole()->loadStructure();
        $this->assertFalse($copy->is_active);
        $this->assertSame($this->test->key, $copy->key);
        $this->assertTrue($copy->isDraft());
        $this->assertSame([$copy->id], $this->test->fresh()->drafts()->pluck('id')->all());
        Livewire::test(ListPlacementTests::class)
            ->assertSee('Đang có bản nháp')
            ->assertSee('Bản nháp của &quot;Đầu vào&quot; (học viên chưa thấy)', false)
            ->assertActionVisible(TestAction::make('openDraft')->table($this->test))
            ->assertActionHidden(TestAction::make('openDraft')->table($copy));

        // Tab của bản nháp: dòng nhắc + nút xem bản đang dùng / dùng bản nháp; tab của bản đang dùng: nút xem bản nháp
        Livewire::test(ManagePlacementQuestions::class, ['record' => $this->test->getRouteKey()])
            ->assertActionVisible('openDraft')->assertActionHidden('publishDraft');
        Livewire::test(ManagePlacementQuestions::class, ['record' => $copy->getRouteKey()])
            ->assertSee('Bản nháp của &quot;Đầu vào&quot; — học viên chưa thấy', false)
            ->assertActionHidden('openDraft')->assertActionVisible('openActive')->assertActionVisible('publishDraft');
        $this->assertCount(3, $copy->questions);
        $newTt = $copy->dimensions->firstWhere('name', 'Tiếp thu');
        $this->assertNotSame($this->dim['tt'], $newTt->id);
        $this->assertSame(3, $copy->questions[0]->options[0]->scoreFor($newTt->id));
        $this->assertSame([$this->course->id, $this->draftCourse->id], $newTt->levels->first()->courses->pluck('id')->sort()->values()->all());

        // Dùng bản nháp (từ tab của bản nháp) → bản cũ tự tắt
        Livewire::test(ManagePlacementQuestions::class, ['record' => $copy->getRouteKey()])->callAction('publishDraft');
        $this->assertTrue($copy->fresh()->is_active);
        $this->assertFalse($this->test->fresh()->is_active);

        // Đã có bài làm → không xoá được
        app(PlacementScoring::class)->submit($this->student, $this->test, $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']));
        Livewire::test(ListPlacementTests::class)
            ->assertActionHidden(TestAction::make('delete')->table($this->test))
            ->assertActionVisible(TestAction::make('delete')->table($copy));
    }

    public function test_new_attempts_badge_and_dots_per_admin(): void
    {
        $scoring = app(PlacementScoring::class);
        $submit = fn () => $scoring->submit($this->student, $this->test, $this->answers(['A'], ['M1'], ['O1', 'O2', 'O3']));
        $otherAdmin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->actingAs($this->admin);

        $this->travelTo(now()->subHour());
        $submit();
        $submit();
        $this->travelBack();

        // Chưa xem lần nào → mọi bài làm đều mới
        $this->assertSame('2', PlacementAttemptResource::getNavigationBadge());
        $this->assertSame('danger', PlacementAttemptResource::getNavigationBadgeColor());

        // Mở danh sách: lần này vẫn chấm đỏ cả 2, sau đó hết số đỏ
        $this->get(ListPlacementAttempts::getUrl())->assertOk()->assertSee('title="Bài làm mới"', false);
        $this->assertNull(PlacementAttemptResource::getNavigationBadge());
        $this->get(ListPlacementAttempts::getUrl())->assertOk()->assertDontSee('title="Bài làm mới"', false);

        // Bài làm mới sau lần xem → số đỏ 1, chỉ dòng đó có chấm; lọc "Chỉ bài làm mới"
        $this->travel(5)->minutes();
        $new = $submit();
        $this->assertSame('1', PlacementAttemptResource::getNavigationBadge());
        Livewire::test(ListPlacementAttempts::class)
            ->assertCanSeeTableRecords(PlacementAttempt::all())
            ->filterTable('new')
            ->assertCanSeeTableRecords([$new])
            ->assertCountTableRecords(1);
        $this->assertNull(PlacementAttemptResource::getNavigationBadge());

        // Admin khác có mốc riêng; mở tab "Bài làm" của bài cũng tính là đã xem
        $this->actingAs($otherAdmin);
        $this->assertSame('3', PlacementAttemptResource::getNavigationBadge());
        $this->get(ManagePlacementAttempts::getUrl(['record' => $this->test]))->assertOk();
        $this->assertNull(PlacementAttemptResource::getNavigationBadge());
        $this->assertNotNull(SeenMarker::get($otherAdmin, SeenMarker::PLACEMENT_ATTEMPTS));

        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(ListPlacementAttempts::getUrl())->assertForbidden();
    }
}

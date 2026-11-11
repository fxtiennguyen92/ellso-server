<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\EnrollmentSource;
use App\Enums\UnlockMode;
use App\Enums\UserRole;
use App\Filament\Resources\Exams\Pages\CreateExam;
use App\Filament\Resources\Exams\Pages\EditExam;
use App\Filament\Resources\Exams\Pages\ListExams;
use App\Filament\Resources\Exams\Pages\ManageExamAttempts;
use App\Filament\Resources\Exams\Pages\ManageExamQuestions;
use App\Filament\Resources\LearningPaths\Pages\BuildLearningPath;
use App\Filament\Resources\Lessons\Pages\ManageLessons;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\PathChapter;
use App\Models\PathLesson;
use App\Models\User;
use App\Services\LearningProgress;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Bài thi (ngân hàng đề, ngang cấp chương) + Coaching trong lộ trình. */
class ExamCoachingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Course $course;

    private LearningPath $path;

    private PathChapter $chapter1;

    private PathChapter $chapter2;

    /** @var array<string, Lesson> */
    private array $lessons = [];

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);

        $this->course = Course::create(['title' => 'Web AI', 'instructor_id' => $this->admin->id, 'price' => 0, 'status' => CourseStatus::Published]);
        $this->path = $this->course->defaultPath;

        // Đề 10 câu chọn 1 (đáp án đúng = "Đúng i") + 1 câu chọn nhiều; mỗi lần rút 5 câu
        $this->exam = Exam::create(['title' => 'Đề giữa kỳ', 'question_count' => 5]);
        foreach (range(1, 10) as $i) {
            $q = $this->exam->questions()->create(['type' => 'single', 'prompt' => "Câu {$i}", 'sort_order' => $i]);
            $q->options()->create(['label' => "Đúng {$i}", 'is_correct' => true, 'sort_order' => 1]);
            $q->options()->create(['label' => "Sai {$i}", 'is_correct' => false, 'sort_order' => 2]);
            $q->options()->create(['label' => "Sai nữa {$i}", 'is_correct' => false, 'sort_order' => 3]);
        }

        // Chương 1: bài A, coaching, bài B · Bài thi giữa kỳ · Chương 2: bài C
        $this->chapter1 = PathChapter::create(['learning_path_id' => $this->path->id, 'title' => 'Chương 1', 'sort_order' => 1]);
        PathChapter::create(['learning_path_id' => $this->path->id, 'kind' => PathChapter::KIND_EXAM, 'exam_id' => $this->exam->id, 'title' => 'Thi giữa kỳ', 'sort_order' => 2]);
        $this->chapter2 = PathChapter::create(['learning_path_id' => $this->path->id, 'title' => 'Chương 2', 'sort_order' => 3]);

        $this->lessons['a'] = $this->lesson('Bài A');
        $this->lessons['coaching'] = Lesson::create(['kind' => Lesson::KIND_COACHING, 'owner_id' => $this->admin->id]);
        $this->lessons['b'] = $this->lesson('Bài B');
        $this->lessons['c'] = $this->lesson('Bài C');
        foreach (['a', 'coaching', 'b'] as $i => $key) {
            PathLesson::create(['path_chapter_id' => $this->chapter1->id, 'lesson_id' => $this->lessons[$key]->id, 'sort_order' => $i + 1, 'unlock_mode' => UnlockMode::AfterPrevious]);
        }
        PathLesson::create(['path_chapter_id' => $this->chapter2->id, 'lesson_id' => $this->lessons['c']->id, 'sort_order' => 1, 'unlock_mode' => UnlockMode::AfterPrevious]);
    }

    private function lesson(string $title): Lesson
    {
        $lesson = Lesson::create(['title' => $title, 'owner_id' => $this->admin->id, 'content_draft' => ['blocks' => [
            ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => $title]],
        ]]]);
        $lesson->publish();

        return $lesson->refresh();
    }

    private function enroll(): CourseEnrollment
    {
        return app(LearningProgress::class)->enroll($this->student, $this->course, EnrollmentSource::Admin, $this->path);
    }

    private function complete(string $key): void
    {
        LessonProgress::updateOrCreate(
            ['user_id' => $this->student->id, 'lesson_id' => $this->lessons[$key]->id],
            ['read_at' => now(), 'completed_at' => now()],
        );
    }

    private function token(): string
    {
        return $this->student->createToken('app', ['app'])->plainTextToken;
    }

    /** Câu trả lời cho bộ câu đã giao: đúng $correct câu đầu, còn lại chọn sai. */
    private function answersFor(array $questions, int $correct): array
    {
        return array_map(function ($q, $i) use ($correct) {
            $want = $i < $correct ? 'Đúng' : 'Sai';
            $option = collect($q['options'])->first(fn ($o) => str_starts_with($o['label'], $want . ' '));

            return ['question_id' => $q['id'], 'option_ids' => [$option['id']]];
        }, $questions, array_keys($questions));
    }

    // ---------- Coaching ----------

    public function test_coaching_needs_previous_but_does_not_block_or_count(): void
    {
        $this->enroll();
        $api = fn () => $this->withToken($this->token());

        $show = $api()->getJson("/v1/my/courses/{$this->course->id}")->assertOk()
            ->assertJsonPath('data.chapters.0.type', 'chapter')
            ->assertJsonPath('data.chapters.0.lessons.1.type', 'coaching')
            ->assertJsonPath('data.chapters.0.lessons.1.title', 'Trao đổi trực tiếp')
            ->assertJsonPath('data.chapters.0.lessons.1.state', 'locked') // bài A chưa xong
            ->assertJsonPath('data.progress.total', 3);                    // coaching không tính
        $this->assertSame('locked', $show->json('data.chapters.0.lessons.2.state'));

        $this->complete('a');
        $api()->getJson("/v1/my/courses/{$this->course->id}")
            ->assertJsonPath('data.chapters.0.lessons.1.state', 'available')
            ->assertJsonPath('data.chapters.0.lessons.2.state', 'available') // coaching không chặn bài B
            ->assertJsonPath('data.progress.completed', 1)
            ->assertJsonPath('data.progress.percent', 33);

        $api()->getJson("/v1/lessons/{$this->lessons['coaching']->id}")->assertOk()
            ->assertJsonPath('data.type', 'coaching')
            ->assertJsonMissingPath('data.content');
        $api()->postJson("/v1/lessons/{$this->lessons['coaching']->id}/read")->assertNotFound();

        // Hoàn thành mọi bài thông thường → hoàn thành khoá dù chưa coaching
        $this->complete('b');
        $this->complete('c');
        app(LearningProgress::class)->refreshCourseCompletion(CourseEnrollment::sole()->load(['learningPath', 'user']));
        $this->assertNotNull(CourseEnrollment::sole()->completed_at);
    }

    // ---------- Bài thi ----------

    public function test_exam_unlocks_after_previous_lessons_and_grades(): void
    {
        $this->enroll();
        $api = fn () => $this->withToken($this->token());
        $examId = PathChapter::where('kind', 'exam')->sole()->id;

        $api()->getJson("/v1/my/courses/{$this->course->id}")->assertOk()
            ->assertJsonPath('data.chapters.1.type', 'exam')
            ->assertJsonPath('data.chapters.1.title', 'Thi giữa kỳ')
            ->assertJsonPath('data.chapters.1.question_count', 5)
            ->assertJsonPath('data.chapters.1.pass_percent', 70)
            ->assertJsonPath('data.chapters.1.state', 'locked')
            ->assertJsonPath('data.chapters.2.type', 'chapter')
            ->assertJsonPath('data.certificate.exams_total', 1);

        $api()->postJson("/v1/my/courses/{$this->course->id}/exams/{$examId}/attempts")->assertForbidden()->assertJsonPath('code', 'exam_locked');

        // Xong A + B (coaching không cần) → bài thi mở
        $this->complete('a');
        $this->complete('b');
        $api()->getJson("/v1/my/courses/{$this->course->id}/exams/{$examId}")->assertOk()->assertJsonPath('data.state', 'available');

        $start = $api()->postJson("/v1/my/courses/{$this->course->id}/exams/{$examId}/attempts")->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonCount(5, 'data.questions');
        $this->assertStringNotContainsString('is_correct', $start->getContent()); // không lộ đáp án
        $questions = $start->json('data.questions');

        // Tải lại → cùng bộ câu
        $again = $api()->postJson("/v1/my/courses/{$this->course->id}/exams/{$examId}/attempts")->assertOk();
        $this->assertSame($questions, $again->json('data.questions'));

        // 3/5 = 60 → chưa đạt
        $attemptId = $start->json('data.id');
        $api()->postJson("/v1/exam-attempts/{$attemptId}/submit", ['answers' => $this->answersFor($questions, 3)])->assertOk()
            ->assertJsonPath('data.score', 60)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.correct_count', 3);
        $api()->postJson("/v1/exam-attempts/{$attemptId}/submit", ['answers' => []])->assertStatus(409)->assertJsonPath('code', 'already_submitted');

        // Làm lại → lần mới, 4/5 = 80 → đậu
        $retry = $api()->postJson("/v1/my/courses/{$this->course->id}/exams/{$examId}/attempts")->assertCreated();
        $this->assertNotSame($attemptId, $retry->json('data.id'));
        $api()->postJson('/v1/exam-attempts/' . $retry->json('data.id') . '/submit', ['answers' => $this->answersFor($retry->json('data.questions'), 4)])
            ->assertJsonPath('data.score', 80)
            ->assertJsonPath('data.passed', true);

        $api()->getJson("/v1/my/courses/{$this->course->id}")
            ->assertJsonPath('data.chapters.1.state', 'passed')
            ->assertJsonPath('data.chapters.1.best_score', 80)
            ->assertJsonPath('data.chapters.1.attempts', 2)
            ->assertJsonPath('data.certificate.exams_passed', 1)
            ->assertJsonPath('data.certificate.eligible', false); // chưa xong bài C

        // Xong khoá + đậu bài thi → đủ điều kiện chứng chỉ; bài thi không tính vào %
        $this->complete('c');
        app(LearningProgress::class)->refreshCourseCompletion(CourseEnrollment::sole()->load(['learningPath', 'user']));
        $api()->getJson('/v1/my/courses')
            ->assertJsonPath('data.0.progress.total', 3)
            ->assertJsonPath('data.0.progress.percent', 100)
            ->assertJsonPath('data.0.certificate.eligible', true);

        // Học viên khác không xem được bài làm này
        $other = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('app', ['app'])->plainTextToken)->getJson("/v1/exam-attempts/{$attemptId}")->assertNotFound();
    }

    public function test_exam_time_limit_auto_submits(): void
    {
        $this->exam->update(['time_limit_minutes' => 10, 'question_count' => null]);
        $this->enroll();
        $this->complete('a');
        $this->complete('b');
        $api = fn () => $this->withToken($this->token());
        $examId = PathChapter::where('kind', 'exam')->sole()->id;

        $start = $api()->postJson("/v1/my/courses/{$this->course->id}/exams/{$examId}/attempts")->assertCreated()
            ->assertJsonCount(10, 'data.questions')
            ->assertJsonPath('data.time_limit_minutes', 10);
        $this->assertNotNull($start->json('data.expires_at'));
        $this->assertSame('Câu 1', $start->json('data.questions.0.prompt')); // không đặt số câu → thứ tự soạn

        // Quá giờ + ân hạn → nộp trễ không được tính, tự chấm 0
        $this->travel(12)->minutes();
        $api()->postJson('/v1/exam-attempts/' . $start->json('data.id') . '/submit', ['answers' => $this->answersFor($start->json('data.questions'), 10)])
            ->assertOk()
            ->assertJsonPath('data.timed_out', true)
            ->assertJsonPath('data.score', 0)
            ->assertJsonPath('data.passed', false);

        // Lần làm dở quá giờ bị tự chấm khi mở lại
        $second = $api()->postJson("/v1/my/courses/{$this->course->id}/exams/{$examId}/attempts")->assertCreated();
        $this->travel(15)->minutes();
        $api()->getJson("/v1/my/courses/{$this->course->id}/exams/{$examId}")->assertJsonPath('data.open_attempt', null)->assertJsonPath('data.attempts', 2);
        $this->assertTrue(ExamAttempt::find($second->json('data.id'))->timed_out);
    }

    public function test_multiple_choice_needs_exact_set(): void
    {
        $this->exam->questions()->delete();
        $q = $this->exam->questions()->create(['type' => 'multiple', 'prompt' => 'Chọn nhiều']);
        $x = $q->options()->create(['label' => 'X', 'is_correct' => true]);
        $y = $q->options()->create(['label' => 'Y', 'is_correct' => true]);
        $z = $q->options()->create(['label' => 'Z', 'is_correct' => false]);
        $this->enroll();
        $this->complete('a');
        $this->complete('b');
        $api = fn () => $this->withToken($this->token());
        $examId = PathChapter::where('kind', 'exam')->sole()->id;

        $id = $api()->postJson("/v1/my/courses/{$this->course->id}/exams/{$examId}/attempts")->json('data.id');
        $api()->postJson("/v1/exam-attempts/{$id}/submit", ['answers' => [['question_id' => $q->id, 'option_ids' => [$x->id]]]])->assertJsonPath('data.score', 0);
        $id = $api()->postJson("/v1/my/courses/{$this->course->id}/exams/{$examId}/attempts")->json('data.id');
        $api()->postJson("/v1/exam-attempts/{$id}/submit", ['answers' => [['question_id' => $q->id, 'option_ids' => [$y->id, $x->id]]]])
            ->assertJsonPath('data.score', 100)->assertJsonPath('data.passed', true);
        $this->assertSame(['X', 'Y'], ExamAttempt::latest('id')->first()->review[0]['correct_labels']);
    }

    // ---------- CMS ----------

    public function test_cms_exam_bank(): void
    {
        $this->actingAs($this->admin);

        $this->get(ListExams::getUrl())->assertOk()->assertSee('Đề giữa kỳ')->assertSee('5 / 10');
        $this->get(ManageExamQuestions::getUrl(['record' => $this->exam]))->assertOk()->assertSee('ngân hàng 10 câu, mỗi lần làm rút ngẫu nhiên 5 câu');
        $this->get(ManageExamAttempts::getUrl(['record' => $this->exam]))->assertOk();

        Livewire::test(CreateExam::class)
            ->fillForm(['title' => 'Cuối kỳ', 'question_count' => null, 'has_time_limit' => true, 'time_limit_minutes' => 30])
            ->call('create')
            ->assertHasNoFormErrors();
        $final = Exam::where('title', 'Cuối kỳ')->sole();
        $this->assertSame(30, $final->time_limit_minutes);

        Livewire::test(EditExam::class, ['record' => $final->getRouteKey()])
            ->assertFormSet(['has_time_limit' => true])
            ->fillForm(['has_time_limit' => false])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($final->fresh()->time_limit_minutes);

        // Câu chọn 1 phải có đúng 1 đáp án đúng
        Livewire::test(ManageExamQuestions::class, ['record' => $final->getRouteKey()])
            ->callAction(TestAction::make('create')->table(), [
                'type' => 'single',
                'prompt' => 'Câu?',
                'options' => [['label' => 'A', 'is_correct' => true], ['label' => 'B', 'is_correct' => true]],
            ])
            ->assertHasFormErrors(['options']);
        Livewire::test(ManageExamQuestions::class, ['record' => $final->getRouteKey()])
            ->callAction(TestAction::make('create')->table(), [
                'type' => 'single',
                'prompt' => 'Câu?',
                'options' => [['label' => 'A', 'is_correct' => true], ['label' => 'B', 'is_correct' => false]],
            ])
            ->assertHasNoFormErrors();
        $this->assertSame([true, false], $final->questions()->sole()->options->pluck('is_correct')->all());

        // Đề đang dùng trong lộ trình → không xoá được
        Livewire::test(ListExams::class)
            ->assertActionHidden(TestAction::make('delete')->table($this->exam))
            ->assertActionVisible(TestAction::make('delete')->table($final));

        // Giảng viên không vào ngân hàng đề
        $this->actingAs(User::factory()->create(['role' => UserRole::Instructor, 'is_active' => true]));
        $this->get(ListExams::getUrl())->assertForbidden();
    }

    public function test_cms_path_builder_exam_and_coaching(): void
    {
        $this->actingAs($this->admin);

        $this->get("/admin/courses/{$this->course->id}/learning-paths/{$this->path->id}/build")->assertOk()
            ->assertSee('Thi giữa kỳ')
            ->assertSee('Chương 2: Chương 2') // bài thi không chiếm số chương
            ->assertSee('Coaching')
            ->assertSee('Thêm bài thi');

        // Trang lồng trong Khoá học: Filament lấy khoá cha từ tham số route {course} của request gốc
        $request = request()->create("/admin/courses/{$this->course->id}/learning-paths/{$this->path->id}/build");
        $route = app('router')->getRoutes()->getByName('filament.admin.resources.courses.learning-paths.build');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('originalRequest', $request);
        $page = Livewire::test(BuildLearningPath::class, ['record' => $this->path->getRouteKey()]);

        // Thêm bài thi cuối kỳ
        $page->callAction('addExam', ['title' => 'Thi cuối kỳ', 'exam_id' => $this->exam->id])->assertHasNoActionErrors();
        $finalExam = PathChapter::where('title', 'Thi cuối kỳ')->sole();
        $this->assertTrue($finalExam->isExam());
        $this->assertSame(4, $finalExam->sort_order);

        // Thêm coaching vào chương 2
        $page->callAction(TestAction::make('addLesson')->arguments(['chapter' => $this->chapter2->id]), [
            'mode' => 'coaching', 'coaching_title' => 'Gặp mentor', 'unlock_mode' => UnlockMode::AfterPrevious->value, 'is_preview' => true,
        ])->assertHasNoActionErrors();
        $coaching = Lesson::where('title', 'Gặp mentor')->sole();
        $this->assertTrue($coaching->isCoaching());
        $item = PathLesson::where('lesson_id', $coaching->id)->sole();
        $this->assertFalse($item->is_preview);

        // Coaching không hiện trong thư viện bài học
        Livewire::test(ManageLessons::class)->assertCanSeeTableRecords([$this->lessons['a']])->assertCanNotSeeTableRecords([$coaching]);

        // Không bật học thử cho coaching; gỡ coaching → xoá luôn
        $page->call('togglePreview', $item->id);
        $this->assertFalse($item->fresh()->is_preview);
        $page->callAction(TestAction::make('removeLesson')->arguments(['item' => $item->id]));
        $this->assertNull(Lesson::withTrashed()->find($coaching->id));

        // Không kéo bài vào bài thi
        $page->call('moveLessons', $finalExam->id, [PathLesson::where('lesson_id', $this->lessons['c']->id)->value('id')]);
        $this->assertSame($this->chapter2->id, PathLesson::where('lesson_id', $this->lessons['c']->id)->value('path_chapter_id'));

        // Đổi thứ tự: bài thi lên đầu
        $page->call('reorderChapters', [$finalExam->id, $this->chapter1->id, $this->chapter2->id]);
        $this->assertSame(1, $finalExam->fresh()->sort_order);
    }
}

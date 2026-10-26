<?php

namespace Tests\Feature;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\EnrollmentSource;
use App\Enums\UnlockMode;
use App\Enums\UserRole;
use App\Models\Classification;
use App\Models\Course;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\PathChapter;
use App\Models\PathLesson;
use App\Models\User;
use App\Services\LearningProgress;
use App\Support\LessonContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Học bài trên app: ghi danh, chọn lộ trình, khoá bài, câu hỏi trắc nghiệm, % hoàn thành. */
class LearningApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Course $course;

    private LearningPath $pathMarketing;

    /** @var array<string, Lesson> */
    private array $lessons = [];

    private int $marketingOption;

    private int $itOption;

    private int $jobId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);
        $this->student = User::factory()->create(['role' => UserRole::Student, 'is_active' => true]);

        $job = Classification::create(['name' => 'Nghề nghiệp', 'question' => 'Bạn làm nghề gì?']);
        $this->jobId = $job->id;
        $this->marketingOption = $job->options()->create(['name' => 'Marketing'])->id;
        $this->itOption = $job->options()->create(['name' => 'IT'])->id;

        $this->course = Course::create(['title' => 'Web AI', 'instructor_id' => $this->admin->id, 'price' => 0, 'type' => CourseType::MultiPath, 'status' => CourseStatus::Published]);
        $this->course->classifications()->sync([$job->id => ['sort_order' => 1]]);
        $this->pathMarketing = LearningPath::create(['course_id' => $this->course->id, 'name' => 'Marketing']);
        $this->pathMarketing->options()->sync([$this->marketingOption]);

        $chapter = PathChapter::create(['learning_path_id' => $this->pathMarketing->id, 'title' => 'Bắt đầu']);
        $this->lessons['intro'] = $this->lesson('Giới thiệu', []);
        $this->lessons['quiz'] = $this->lesson('Có câu hỏi', [
            $this->quizBlock('q1', 'single', [['a', 'Đúng', true], ['b', 'Sai', false]], 'Vì a đúng'),
            $this->quizBlock('q2', 'multiple', [['x', 'X', true], ['y', 'Y', true], ['z', 'Z', false]]),
        ]);
        $this->lessons['last'] = $this->lesson('Bài cuối', []);
        $draft = Lesson::create(['title' => 'Chưa xuất bản', 'owner_id' => $this->admin->id]);
        foreach ([$this->lessons['intro'], $this->lessons['quiz'], $draft, $this->lessons['last']] as $i => $lesson) {
            PathLesson::create(['path_chapter_id' => $chapter->id, 'lesson_id' => $lesson->id, 'sort_order' => $i + 1, 'unlock_mode' => UnlockMode::AfterPrevious]);
        }
    }

    private function quizBlock(string $id, string $type, array $options, string $explanation = ''): array
    {
        return ['id' => $id, 'type' => 'quiz', 'data' => [
            'type' => $type,
            'question' => "Câu {$id}?",
            'options' => array_map(fn ($o) => ['id' => $o[0], 'text' => $o[1], 'correct' => $o[2]], $options),
            'explanation' => $explanation,
        ]];
    }

    private function lesson(string $title, array $quizBlocks): Lesson
    {
        $lesson = Lesson::create(['title' => $title, 'owner_id' => $this->admin->id, 'content_draft' => ['blocks' => [
            ['id' => 'p1', 'type' => 'paragraph', 'data' => ['text' => 'Nội dung ' . $title]],
            ['id' => 'img', 'type' => 'image', 'data' => ['file' => ['url' => 'http://cms.test:8000/storage/lessons/content/1/a.png']]],
            ...$quizBlocks,
        ]]]);
        $lesson->publish();

        return $lesson->refresh();
    }

    public function test_full_learning_flow(): void
    {
        $token = $this->student->createToken('app', ['app'])->plainTextToken;
        $api = fn () => $this->withToken($token);

        $api()->getJson('/v1/my/courses')->assertOk()->assertJsonCount(0, 'data');
        $api()->getJson("/v1/lessons/{$this->lessons['intro']->id}")->assertForbidden()->assertJsonPath('code', 'not_enrolled');

        app(LearningProgress::class)->enroll($this->student, $this->course, EnrollmentSource::Admin, by: $this->admin);

        // Chưa có lộ trình → câu hỏi phân loại
        $api()->getJson('/v1/my/courses')->assertOk()
            ->assertJsonPath('data.0.needs_path', true)
            ->assertJsonPath('data.0.progress.percent', null);
        $api()->getJson("/v1/my/courses/{$this->course->id}")->assertOk()
            ->assertJsonPath('data.questions.0.question', 'Bạn làm nghề gì?')
            ->assertJsonCount(2, 'data.questions.0.options');

        $api()->postJson("/v1/my/courses/{$this->course->id}/path", ['answers' => []])->assertUnprocessable();
        $map = $api()->postJson("/v1/my/courses/{$this->course->id}/path", ['answers' => [
            $this->jobId => [$this->marketingOption],
        ]])->assertOk()
            ->assertJsonPath('data.path.name', 'Marketing')
            ->assertJsonPath('data.progress.total', 3) // bài chưa xuất bản không tính
            ->assertJsonPath('data.chapters.0.lessons.0.state', 'available')
            ->assertJsonPath('data.chapters.0.lessons.1.state', 'locked');
        $this->assertSame([$this->marketingOption], $this->student->classificationOptions()->pluck('classification_options.id')->all());

        // Bài bị khoá
        $api()->getJson("/v1/lessons/{$this->lessons['quiz']->id}")->assertForbidden()->assertJsonPath('code', 'lesson_locked');

        // Bài 1: không có câu hỏi → đọc hết là xong
        $api()->getJson("/v1/lessons/{$this->lessons['intro']->id}")->assertOk()
            ->assertJsonPath('data.content.blocks.1.data.file.url', 'http://localhost/storage/lessons/content/1/a.png')
            ->assertJsonPath('data.progress.completed', false);
        $api()->postJson("/v1/lessons/{$this->lessons['intro']->id}/read")->assertOk()->assertJsonPath('data.completed', true);

        // Bài 2: nội dung không lộ đáp án
        $res = $api()->getJson("/v1/lessons/{$this->lessons['quiz']->id}")->assertOk()
            ->assertJsonPath('data.quiz_count', 2)
            ->assertJsonPath('data.content.blocks.2.data.options', [['id' => 'a', 'text' => 'Đúng'], ['id' => 'b', 'text' => 'Sai']]);
        $this->assertStringNotContainsString('"correct"', $res->getContent());
        $this->assertStringNotContainsString('Vì a đúng', $res->getContent());

        $url = "/v1/lessons/{$this->lessons['quiz']->id}/quiz";
        $api()->postJson($url, ['block_id' => 'q1', 'option_ids' => ['b']])->assertOk()
            ->assertJsonPath('data.correct', false)->assertJsonPath('data.explanation', 'Vì a đúng');
        $api()->postJson($url, ['block_id' => 'q1', 'option_ids' => ['a', 'b']])->assertJsonPath('data.correct', false);
        $api()->postJson($url, ['block_id' => 'q1', 'option_ids' => ['a']])->assertJsonPath('data.correct', true)
            ->assertJsonPath('data.progress.quizzes_correct', 1);
        $api()->postJson($url, ['block_id' => 'q2', 'option_ids' => ['x']])->assertJsonPath('data.correct', false);
        $api()->postJson($url, ['block_id' => 'q2', 'option_ids' => ['y', 'x']])->assertJsonPath('data.correct', true)
            ->assertJsonPath('data.progress.completed', false); // chưa đọc hết
        $api()->postJson($url, ['block_id' => 'khong-co', 'option_ids' => ['a']])->assertNotFound();
        $api()->postJson("/v1/lessons/{$this->lessons['quiz']->id}/read")->assertJsonPath('data.completed', true);

        // Bài 3 mở → xong → khoá hoàn thành 100%
        $api()->postJson("/v1/lessons/{$this->lessons['last']->id}/read")->assertOk()->assertJsonPath('data.completed', true);
        $api()->getJson('/v1/my/courses')
            ->assertJsonPath('data.0.progress.percent', 100)
            ->assertJsonPath('data.0.next_lesson', null);
        $this->assertNotNull($this->student->enrollments()->first()->completed_at);
        $this->assertSame(5, $this->student->quizAnswers()->count()); // 5 lần trả lời hợp lệ
    }

    public function test_quiz_validation_for_publish(): void
    {
        $content = ['blocks' => [
            $this->quizBlock('q1', 'single', [['a', 'A', true], ['b', 'B', true]]),
            $this->quizBlock('q2', 'multiple', [['a', 'A', false], ['b', 'B', false]]),
            ['id' => 'q3', 'type' => 'quiz', 'data' => ['type' => 'single', 'question' => '', 'options' => []]],
            $this->quizBlock('q4', 'single', [['a', '<b>A</b><script>x</script>', true], ['b', 'B', false]]),
        ]];

        $this->assertSame([
            'Câu hỏi 1: "Chọn 1" chỉ được có 1 đáp án đúng.',
            'Câu hỏi 2: chưa đánh dấu đáp án đúng.',
            'Câu hỏi 3: chưa có nội dung câu hỏi.',
        ], LessonContent::quizProblems($content));
        $this->assertSame(['q4'], array_keys(LessonContent::quizzes($content)));

        $clean = LessonContent::sanitize($content, 'http://localhost/storage/');
        $this->assertSame('Ax', $clean['blocks'][3]['data']['options'][0]['text']); // chữ thuần
    }

    public function test_placement_test_required_blocks_learning(): void
    {
        $test = \App\Models\PlacementTest::create(['title' => 'Đầu vào', 'is_active' => true]);
        $this->course->update(['requires_placement_test' => true]);
        app(LearningProgress::class)->enroll($this->student, $this->course->fresh(), EnrollmentSource::Admin, $this->pathMarketing);
        $token = $this->student->createToken('app', ['app'])->plainTextToken;

        $this->withToken($token)->getJson('/v1/my/courses')->assertJsonPath('data.0.needs_placement_test', true)->assertJsonPath('data.0.needs_path', false);
        $this->withToken($token)->getJson("/v1/lessons/{$this->lessons['intro']->id}")->assertStatus(409)->assertJsonPath('code', 'placement_test_required');

        \App\Models\PlacementAttempt::create(['user_id' => $this->student->id, 'placement_test_id' => $test->id, 'answers' => [], 'results' => [], 'completed_at' => now()]);
        $this->withToken($token)->getJson("/v1/lessons/{$this->lessons['intro']->id}")->assertOk();
    }
}

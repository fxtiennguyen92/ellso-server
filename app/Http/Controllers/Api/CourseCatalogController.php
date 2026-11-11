<?php

namespace App\Http\Controllers\Api;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Enums\EnrollmentSource;
use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Course;
use App\Models\User;
use App\Services\LearningProgress;
use App\Support\HtmlBlocks;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Khám phá khoá học (app, đã đăng nhập).
 * - GET /courses?category_id=&page=   khoá đã xuất bản, mới nhất trước, 10 khoá / trang (app tải thêm khi cuộn)
 * - GET /courses?q=&page=&per_page=   tìm kiếm (tên khoá / mô tả ngắn / người hướng dẫn / danh mục), tải từng đợt
 * - GET /courses/categories           danh mục có khoá đã xuất bản (để lọc)
 * - GET /courses/{id}                  trang giới thiệu khoá (khoá đã xuất bản, hoặc khoá học viên đã ghi danh)
 * - POST /courses/{id}/enroll          ghi danh khoá MIỄN PHÍ (nút "Bắt đầu học"); đã ghi danh → giữ nguyên
 * Mỗi khoá có `enrolled`, `progress_percent` (null nếu chưa ghi danh / chưa có lộ trình), `started` (đã học ≥ 1 bài
 * hoặc đã có hoạt động) → app hiện "Bắt đầu học" / "Tiếp tục học".
 */
class CourseCatalogController extends Controller
{
    public const PER_PAGE = 10;

    /** Khoá xuất bản trong số ngày này được gắn nhãn "Mới". */
    public const NEW_DAYS = 30;

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            // Tìm kiếm (màn Tìm kiếm của app): theo tên khoá, mô tả ngắn, tên người hướng dẫn, tên danh mục; per_page để tải từng đợt
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);
        $term = trim((string) ($data['q'] ?? ''));

        $page = Course::query()
            ->where('status', CourseStatus::Published)
            ->when($data['category_id'] ?? null, function ($q, $categoryId) {
                $ids = Category::query()->where('parent_id', $categoryId)->pluck('id')->push((int) $categoryId);
                $q->whereIn('category_id', $ids);
            })
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$term}%")
                ->orWhere('short_description', 'like', "%{$term}%")
                ->orWhereHas('instructor', fn ($i) => $i->where('name', 'like', "%{$term}%"))
                ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$term}%"))))
            ->with(['category', 'instructor'])
            ->latest('published_at')
            ->latest('id')
            ->paginate($data['per_page'] ?? self::PER_PAGE);

        return response()->json([
            'data' => self::present(collect($page->items()), $request->user()),
            'meta' => [
                'page' => $page->currentPage(),
                'has_more' => $page->hasMorePages(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function categories(): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->whereHas('courses', fn ($q) => $q->where('status', CourseStatus::Published))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $categories]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $course = Course::query()->with(['category', 'instructor', 'defaultPath.chapters.pathLessons.lesson'])->find($id);
        $enrolled = $course && $user->enrollments()->where('course_id', $course->id)->exists();
        if (! $course || ($course->status !== CourseStatus::Published && ! $enrolled)) {
            return response()->json(['message' => 'Khoá học không tồn tại.', 'code' => 'not_found'], 404);
        }

        $item = self::present(collect([$course]), $user)[0];
        $personalized = $course->type === CourseType::MultiPath;
        $pending = $course->pendingPlacementTests($user)->pluck('id')->all();

        // Nội dung (đề cương) theo lộ trình mặc định — khoá nhiều lộ trình: mỗi học viên một lộ trình riêng nên không liệt kê
        $outline = [];
        if (! $personalized && $course->defaultPath) {
            foreach ($course->defaultPath->chapters as $chapter) {
                if ($chapter->isExam()) {
                    $outline[] = ['type' => 'exam', 'title' => $chapter->title, 'color' => null, 'lessons' => []];

                    continue;
                }
                $lessons = $chapter->pathLessons
                    ->filter(fn ($pl) => $pl->lesson && $pl->lesson->isPublished())
                    ->map(fn ($pl) => ['title' => $pl->lesson->title, 'is_preview' => (bool) $pl->is_preview, 'coaching' => $pl->lesson->isCoaching()])
                    ->values()->all();
                if ($lessons) {
                    $outline[] = ['type' => 'chapter', 'title' => $chapter->title, 'color' => $chapter->color?->value, 'lessons' => $lessons];
                }
            }
        }

        return response()->json(['data' => [
            ...$item,
            'description_blocks' => HtmlBlocks::toBlocks($course->description),
            'personalized' => $personalized,
            'paths_count' => $personalized ? $course->learningPaths()->count() : 1,
            'chapter_count' => count(array_filter($outline, fn ($c) => $c['type'] === 'chapter')),
            'outline' => $outline,
            'instructor_bio' => $course->instructor?->bio,
            'placement_tests' => $course->requiredPlacementTests()->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'completed' => ! in_array($t->id, $pending, true),
            ])->values(),
        ]]);
    }

    public function enroll(Request $request, int $id, LearningProgress $progress): JsonResponse
    {
        $user = $request->user();
        $course = Course::query()->find($id);
        if (! $course || $course->status !== CourseStatus::Published) {
            return response()->json(['message' => 'Khoá học không tồn tại.', 'code' => 'not_found'], 404);
        }
        $already = $user->enrollments()->where('course_id', $course->id)->exists();
        if (! $already && ! $course->isFree()) {
            return response()->json(['message' => 'Khoá học này cần mua trước khi học.', 'code' => 'not_free'], 422);
        }
        if (! $already) {
            $progress->enroll($user, $course, EnrollmentSource::Free);
        }

        return response()->json(['data' => self::present(collect([$course->load(['category', 'instructor'])]), $user)[0]], $already ? 200 : 201);
    }

    /** Định dạng giống src/data/courses.ts › CatalogCourse của app. */
    public static function present(Collection $courses, User $user): array
    {
        $ids = $courses->pluck('id');
        $enrollments = $user->enrollments()->whereIn('course_id', $ids)->with(['learningPath', 'user'])->get()->keyBy('course_id');
        $enrolled = $enrollments->keys()->all();
        $learning = app(LearningProgress::class);
        $progress = $enrollments->map(function ($e) use ($learning) {
            $s = $learning->summary($e);

            return ['percent' => $s['percent'], 'started' => $s['completed'] > 0 || $e->last_activity_at !== null];
        });
        $inCart = $user->cartItems()->where('item_type', CartItem::TYPE_COURSE)->whereIn('item_id', $ids)->pluck('item_id')->all();
        $lessonCounts = $courses->mapWithKeys(fn (Course $c) => [$c->id => $c->lessonsQuery()->count()]);

        return $courses->map(fn (Course $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'short_description' => $c->short_description,
            'category' => $c->category ? ['id' => $c->category->id, 'name' => $c->category->name] : null,
            'instructor' => $c->instructor ? [
                'id' => $c->instructor->id,
                'name' => $c->instructor->name,
                'avatar' => $c->instructor->avatarUrl(),
            ] : null,
            'cover_url' => $c->cover_image ? MediaStorage::disk()->url($c->cover_image) : null,
            'price' => (int) $c->price,
            'lesson_count' => $lessonCounts[$c->id] ?? 0,
            'is_new' => $c->published_at?->gt(now()->subDays(self::NEW_DAYS)) ?? false,
            'enrolled' => in_array($c->id, $enrolled, true),
            'progress_percent' => $progress[$c->id]['percent'] ?? null,
            'started' => $progress[$c->id]['started'] ?? false,
            'in_cart' => in_array($c->id, $inCart, true),
        ])->values()->all();
    }
}

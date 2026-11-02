<?php

namespace App\Models;

use App\Enums\CourseStatus;
use App\Enums\CourseType;
use App\Models\Concerns\CleansUpFiles;
use App\Models\Concerns\HasUniqueSlug;
use App\Support\AbilityClassifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use CleansUpFiles, HasUniqueSlug, SoftDeletes;

    /** Ảnh bìa: xoá file khi bỏ / thay ảnh, hoặc khi xoá vĩnh viễn khỏi thùng rác. */
    protected array $fileAttributes = ['cover_image'];

    protected string $slugSource = 'title';

    protected $fillable = [
        'category_id',
        'instructor_id',
        'title',
        'slug',
        'short_description',
        'description',
        'cover_image',
        'price',
        'type',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'type' => CourseType::class,
            'status' => CourseStatus::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Course $course) {
            // Lần đầu xuất bản → ghi lại thời điểm
            if ($course->status === CourseStatus::Published && blank($course->published_at)) {
                $course->published_at = now();
            }
        });

        // Khóa học chung = khóa có đúng 1 lộ trình mặc định → tạo sẵn
        static::created(function (Course $course) {
            $course->ensureDefaultPath();
        });

        static::updated(function (Course $course) {
            if ($course->wasChanged('type')) {
                $course->ensureDefaultPath();
            }
        });
    }

    public function ensureDefaultPath(): void
    {
        if ($this->learningPaths()->exists()) {
            return;
        }

        $this->learningPaths()->create([
            'name' => $this->type === CourseType::Single ? 'Lộ trình chung' : 'Lộ trình mặc định',
            'is_default' => true,
        ]);
    }

    public function isFree(): bool
    {
        return $this->price === 0;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /** Các bài (trong thư viện) đang được dùng ở lộ trình của khóa này. */
    public function lessonsQuery(): Builder
    {
        return Lesson::query()->whereHas('pathLessons.learningPath', fn ($query) => $query->where('course_id', $this->id));
    }

    public function learningPaths(): HasMany
    {
        return $this->hasMany(LearningPath::class)->orderBy('sort_order');
    }

    public function defaultPath(): HasOne
    {
        return $this->hasOne(LearningPath::class)->where('is_default', true);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    /** Các mức của bài kiểm tra đầu vào gợi ý khoá này. */
    public function placementLevels(): BelongsToMany
    {
        return $this->belongsToMany(PlacementLevel::class, 'placement_level_courses');
    }

    /** Các phân loại khóa dùng để chia lộ trình, theo thứ tự câu hỏi. */
    public function classifications(): BelongsToMany
    {
        return $this->belongsToMany(Classification::class, 'course_classifications')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    /** Dạng hasMany của bảng nối — dùng cho Repeater kéo thả trên CMS. */
    public function courseClassifications(): HasMany
    {
        return $this->hasMany(CourseClassification::class)->orderBy('sort_order');
    }

    /** Phân loại riêng của khóa (course_id = khóa này). */
    public function ownClassifications(): HasMany
    {
        return $this->hasMany(Classification::class);
    }

    /** Các bài kiểm tra đầu vào bắt buộc (theo key bài, có thứ tự). */
    public function placementTestRequirements(): HasMany
    {
        return $this->hasMany(CoursePlacementTest::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Phiên bản đang dùng của các bài bắt buộc. Bài đang tắt bị bỏ qua (học viên không làm được thì không chặn).
     *
     * @return Collection<int, PlacementTest>
     */
    public function requiredPlacementTests(): Collection
    {
        $keys = $this->placementTestRequirements->pluck('placement_test_key')->all();
        if ($keys === []) {
            return new Collection;
        }

        $active = PlacementTest::query()->active()->whereIn('key', $keys)->get()->keyBy('key');

        return new Collection(array_values(array_filter(array_map(fn ($key) => $active[$key] ?? null, $keys))));
    }

    /**
     * Bài bắt buộc mà học viên chưa làm (đã làm một phiên bản bất kỳ của bài = đã làm).
     *
     * @return Collection<int, PlacementTest>
     */
    public function pendingPlacementTests(User $user): Collection
    {
        $required = $this->requiredPlacementTests();
        if ($required->isEmpty()) {
            return $required;
        }

        $done = PlacementAttempt::query()
            ->where('user_id', $user->id)
            ->join('placement_tests', 'placement_tests.id', '=', 'placement_attempts.placement_test_id')
            ->whereIn('placement_tests.key', $required->pluck('key')->all())
            ->distinct()
            ->pluck('placement_tests.key')
            ->all();

        return $required->reject(fn (PlacementTest $test) => in_array($test->key, $done, true))->values();
    }

    /** Học viên phải làm bài kiểm tra đầu vào (ít nhất một bài bắt buộc chưa làm) trước bài học đầu tiên không? */
    public function needsPlacementTest(User $user): bool
    {
        return $this->pendingPlacementTests($user)->isNotEmpty();
    }

    /** Đặt danh sách bài bắt buộc (key bài, theo thứ tự). */
    public function syncPlacementTestKeys(array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys)));
        $this->placementTestRequirements()->whereNotIn('placement_test_key', $keys)->delete();
        foreach ($keys as $i => $key) {
            $this->placementTestRequirements()->updateOrCreate(['placement_test_key' => $key], ['sort_order' => $i + 1]);
        }
        $this->unsetRelation('placementTestRequirements');
    }

    /**
     * Gợi ý lộ trình cho học viên: câu trả lời phân loại "Câu hỏi" + giá trị "Năng lực" lấy tự động
     * từ bài làm mới nhất của từng bài kiểm tra đầu vào. Chưa làm bài → điều kiện năng lực không khớp với ai
     * (lộ trình không đặt điều kiện năng lực vẫn khớp bình thường).
     *
     * @param  array<int>  $answeredOptionIds
     */
    public function suggestPathFor(User $user, array $answeredOptionIds): ?LearningPath
    {
        return $this->suggestPath([...$answeredOptionIds, ...AbilityClassifications::optionIdsFor($user)]);
    }

    /**
     * Gợi ý lộ trình từ câu trả lời của học viên.
     *
     * Lộ trình khớp khi, với mọi phân loại khóa đang dùng, lộ trình không đặt điều kiện
     * (= mọi giá trị) hoặc có chứa giá trị học viên chọn. Lộ trình khớp nhiều điều kiện
     * nhất được chọn; không có lộ trình nào khớp → lộ trình mặc định.
     *
     * @param  array<int>  $selectedOptionIds
     */
    public function suggestPath(array $selectedOptionIds): ?LearningPath
    {
        $selected = collect($selectedOptionIds)->map(fn ($id) => (int) $id);
        $classificationIds = $this->classifications()->pluck('classifications.id');

        $best = null;
        $bestScore = -1;

        foreach ($this->learningPaths()->with('options')->get() as $path) {
            $score = 0;

            foreach ($classificationIds as $classificationId) {
                $required = $path->options->where('classification_id', $classificationId)->pluck('id');

                if ($required->isEmpty()) {
                    continue; // mọi giá trị
                }

                if ($required->intersect($selected)->isEmpty()) {
                    continue 2; // không khớp
                }

                $score++;
            }

            if ($score > $bestScore) {
                [$best, $bestScore] = [$path, $score];
            }
        }

        return $best ?? $this->defaultPath;
    }
}

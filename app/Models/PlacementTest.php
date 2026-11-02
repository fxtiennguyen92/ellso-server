<?php

namespace App\Models;

use App\Support\AbilityClassifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Collection as SupportCollection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Bài kiểm tra đầu vào. Có thể có nhiều bài cùng dùng, mỗi bài đánh giá một nhóm năng lực riêng.
 *
 * - key: mã ổn định của bài. Các phiên bản (nhân bản để sửa đề) dùng chung key; mỗi key chỉ một phiên bản
 *   đang dùng — bật phiên bản mới tự tắt phiên bản cũ. Khoá học bắt buộc làm bài theo key, và học viên
 *   đã làm một phiên bản bất kỳ được tính là đã làm bài đó.
 * - sort_order: thứ tự hiện trên app.
 * - question_count: số câu mỗi lần làm (null = tất cả, theo thứ tự soạn). Có số → rút ngẫu nhiên từ ngân hàng câu
 *   (ưu tiên mỗi năng lực có ít nhất 1 câu), thứ tự ngẫu nhiên. shuffle_options: đảo thứ tự đáp án. Xem draw().
 * Cách chấm điểm: xem App\Services\PlacementScoring.
 */
class PlacementTest extends Model
{
    protected $fillable = ['key', 'title', 'intro', 'question_count', 'shuffle_options', 'is_active', 'sort_order'];

    protected $attributes = ['shuffle_options' => true, 'is_active' => false];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'question_count' => 'integer',
            'shuffle_options' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PlacementTest $test) {
            $test->key ??= (string) Str::uuid();
            if (! $test->sort_order) {
                $test->sort_order = (int) static::query()->max('sort_order') + 1;
            }
        });

        static::saved(function (PlacementTest $test) {
            // Bật phiên bản này → tắt các phiên bản khác của cùng bài
            if ($test->is_active) {
                static::query()->whereKeyNot($test->getKey())->where('key', $test->key)->where('is_active', true)->update(['is_active' => false]);
            }
            // Đổi bài đang dùng → tên / mức của phân loại Năng lực theo bài mới
            if ($test->wasChanged('is_active') || $test->wasRecentlyCreated) {
                AbilityClassifications::syncSoon();
            }
        });
        static::deleted(fn () => AbilityClassifications::syncSoon());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** Các bài đang dùng, theo thứ tự hiện trên app. */
    public static function activeTests(): Collection
    {
        return static::query()->active()->ordered()->get();
    }

    /** Phiên bản đang dùng của bài có key này (null nếu bài đang tắt). */
    public static function activeFor(string $key): ?self
    {
        return static::query()->active()->where('key', $key)->latest('id')->first();
    }

    /**
     * Danh sách "bài" (mỗi key một dòng) để chọn trong form: phiên bản đang dùng, không thì phiên bản mới nhất.
     *
     * @return array<string, string> key => tên
     */
    public static function keyOptions(): array
    {
        return static::query()->ordered()->get()
            ->groupBy('key')
            ->map(function ($versions) {
                $test = $versions->firstWhere('is_active', true) ?? $versions->sortByDesc('id')->first();

                return $test->title . ($test->is_active ? '' : ' (đang tắt)');
            })
            ->all();
    }

    /** Các phiên bản khác của cùng bài. */
    public function siblings(): HasMany
    {
        return $this->hasMany(self::class, 'key', 'key')->whereKeyNot($this->getKey());
    }

    /** Các khoá học bắt buộc làm bài này (theo key). */
    public function requiringCourses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_placement_tests', 'placement_test_key', 'course_id', 'key');
    }

    public function dimensions(): HasMany
    {
        return $this->hasMany(PlacementDimension::class)->orderBy('sort_order')->orderBy('id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(PlacementQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PlacementAttempt::class);
    }

    /**
     * Nhân bản toàn bộ đề (năng lực, mức độ, khoá gợi ý, câu hỏi, đáp án + điểm) thành bài chưa dùng.
     *
     * - $asNewVersion = true: phiên bản mới của cùng bài (giữ key bài + key năng lực / mức) — để sửa đề mà không
     *   làm lẫn thống kê của bài đang chạy; bật bản sao thì bản cũ tự tắt, điều kiện khoá học / lộ trình giữ nguyên.
     * - $asNewVersion = false: bài mới độc lập (key mới, năng lực mới) — dùng đề cũ làm mẫu.
     */
    public function duplicate(bool $asNewVersion = true): self
    {
        $this->loadStructure();

        return AbilityClassifications::batch(fn () => DB::transaction(function () use ($asNewVersion) {
            $copy = static::create([
                'key' => $asNewVersion ? $this->key : null,
                'title' => $this->title . ' (bản sao)',
                'intro' => $this->intro,
                'question_count' => $this->question_count,
                'shuffle_options' => $this->shuffle_options,
                'is_active' => false,
                'sort_order' => $asNewVersion ? $this->sort_order : null,
            ]);

            $keep = $asNewVersion ? ['key'] : [];
            $dimensionMap = [];
            foreach ($this->dimensions as $dimension) {
                $newDimension = $copy->dimensions()->create($dimension->only([...$keep, 'name', 'description', 'sort_order']));
                $dimensionMap[$dimension->id] = $newDimension->id;

                foreach ($dimension->levels as $level) {
                    $newLevel = $newDimension->levels()->create($level->only([...$keep, 'name', 'min_percent', 'description', 'sort_order']));
                    $newLevel->courses()->sync($level->courses->pluck('id'));
                }
            }

            foreach ($this->questions as $question) {
                $newQuestion = $copy->questions()->create($question->only([
                    'type', 'prompt', 'hint', 'min_choices', 'max_choices', 'position_weights', 'sort_order',
                ]));

                foreach ($question->options as $option) {
                    $scores = [];
                    foreach ($option->scores ?? [] as $dimensionId => $points) {
                        if (isset($dimensionMap[$dimensionId])) {
                            $scores[$dimensionMap[$dimensionId]] = $points;
                        }
                    }
                    $newQuestion->options()->create([
                        'label' => $option->label,
                        'scores' => $scores,
                        'sort_order' => $option->sort_order,
                    ]);
                }
            }

            return $copy;
        }));
    }

    /** Có rút ngẫu nhiên câu hỏi không (đặt số câu nhỏ hơn hoặc bằng tổng số câu). */
    public function drawsQuestions(): bool
    {
        return $this->question_count !== null && $this->question_count > 0;
    }

    /** Số câu học viên nhận mỗi lần làm. */
    public function questionsPerAttempt(?int $total = null): int
    {
        $total ??= $this->relationLoaded('questions') ? $this->questions->count() : $this->questions()->count();

        return $this->drawsQuestions() ? min($this->question_count, $total) : $total;
    }

    /**
     * Seed rút câu cho một học viên: cố định cho đến khi học viên nộp bài (tải lại vẫn ra đúng bộ câu đó),
     * lần làm sau ra bộ khác. Đổi đề / cấu hình thì bộ câu đổi theo.
     */
    public function seedFor(User $user): int
    {
        $attemptNo = $user->placementAttempts()->where('placement_test_id', $this->id)->count();

        return crc32(config('app.key') . "|placement|{$this->id}|{$user->id}|{$attemptNo}");
    }

    /**
     * Bộ câu cho một lần làm (đã nạp đáp án), theo thứ tự hiển thị; đáp án mỗi câu cũng theo thứ tự hiển thị.
     *
     * - Không đặt số câu: mọi câu theo thứ tự soạn.
     * - Đặt số câu N: rút N câu. Trước hết mỗi năng lực (thứ tự ngẫu nhiên) lấy 1 câu có cộng điểm cho nó, nếu còn chỗ;
     *   phần còn lại rút ngẫu nhiên. Rồi xáo thứ tự câu.
     * - shuffle_options: xáo thứ tự đáp án (cả mục của câu sắp xếp — vị trí ban đầu không ảnh hưởng điểm).
     *
     * @return SupportCollection<int, PlacementQuestion>
     */
    public function draw(int $seed): SupportCollection
    {
        $this->loadMissing(['questions.options', 'dimensions']);
        $random = new Randomizer(new Mt19937($seed));
        $questions = $this->questions->values();

        if ($this->drawsQuestions() && $questions->isNotEmpty()) {
            $count = min($this->question_count, $questions->count());
            $pool = $questions->keyBy('id');
            $chosen = [];

            $dimensionIds = $this->dimensions->pluck('id')->all();
            $dimensionIds = $dimensionIds === [] ? [] : $random->shuffleArray($dimensionIds);
            foreach ($dimensionIds as $dimensionId) {
                if (count($chosen) >= $count) {
                    break;
                }
                $covers = $pool->filter(fn (PlacementQuestion $q) => ! isset($chosen[$q->id])
                    && $q->options->contains(fn ($o) => $o->scoreFor($dimensionId) !== 0))->keys()->all();
                if ($covers === [] || collect($chosen)->contains(fn ($q) => $q->options->contains(fn ($o) => $o->scoreFor($dimensionId) !== 0))) {
                    continue;
                }
                $id = $covers[$random->getInt(0, count($covers) - 1)];
                $chosen[$id] = $pool[$id];
            }

            $rest = $pool->keys()->reject(fn ($id) => isset($chosen[$id]))->values()->all();
            $rest = $rest === [] ? [] : $random->shuffleArray($rest);
            foreach (array_slice($rest, 0, $count - count($chosen)) as $id) {
                $chosen[$id] = $pool[$id];
            }

            $questions = collect($random->shuffleArray(array_values($chosen)));
        }

        return $questions->map(function (PlacementQuestion $question) use ($random) {
            $question = clone $question;
            if ($this->shuffle_options && $question->options->count() > 1) {
                $question->setRelation('options', new Collection($random->shuffleArray($question->options->all())));
            }

            return $question;
        })->values();
    }

    /** Bản sao của bài với relation questions = bộ câu đã rút (để chấm điểm / hiển thị trên bộ câu đó). */
    public function withDrawnQuestions(int $seed): static
    {
        $drawn = $this->draw($seed);
        $copy = clone $this;
        $copy->setRelation('questions', new Collection($drawn->all()));

        return $copy;
    }

    /** Nạp đủ đề + cách chấm để chấm điểm / hiển thị. */
    public function loadStructure(): static
    {
        return $this->load(['questions.options', 'dimensions.levels.courses']);
    }
}

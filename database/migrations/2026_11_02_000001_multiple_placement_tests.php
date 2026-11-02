<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Nhiều bài kiểm tra đầu vào cùng lúc, mỗi bài đánh giá một nhóm năng lực riêng.
 *
 * - placement_tests.key: mã ổn định của "bài" — các phiên bản (bản nhân bản để sửa đề) dùng chung key.
 *   Mỗi key chỉ một phiên bản đang dùng. Khoá học, kết quả của học viên… gắn theo key, nên bật phiên bản
 *   mới không làm mất điều kiện / bài đã làm.
 * - placement_tests.sort_order: thứ tự hiện trên app.
 * - course_placement_tests: khoá học bắt buộc làm những bài nào trước khi học (thay cho requires_placement_test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->uuid('key')->nullable()->after('id')->index();
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
        });

        // Bài cũ: các bản nhân bản chia sẻ key năng lực → cùng một "bài" (cùng key)
        $tests = DB::table('placement_tests')->orderBy('id')->pluck('id')->all();
        $dimensionKeys = DB::table('placement_dimensions')->whereNotNull('key')->get(['placement_test_id', 'key'])
            ->groupBy('placement_test_id')->map(fn ($rows) => $rows->pluck('key')->all());

        $parent = array_combine($tests, $tests) ?: [];
        $find = function ($id) use (&$parent, &$find) {
            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };
        $byKey = [];
        foreach ($dimensionKeys as $testId => $keys) {
            foreach ($keys as $key) {
                if (isset($byKey[$key])) {
                    $parent[$find($testId)] = $find($byKey[$key]);
                } else {
                    $byKey[$key] = $testId;
                }
            }
        }

        $groupKeys = [];
        foreach ($tests as $i => $id) {
            $root = $find($id);
            $groupKeys[$root] ??= (string) Str::uuid();
            DB::table('placement_tests')->where('id', $id)->update(['key' => $groupKeys[$root], 'sort_order' => $i + 1]);
        }

        Schema::create('course_placement_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->uuid('placement_test_key')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['course_id', 'placement_test_key']);
        });

        // Khoá đang bật "bắt buộc làm bài kiểm tra đầu vào" → bắt buộc bài đang dùng
        $activeKey = DB::table('placement_tests')->where('is_active', true)->orderByDesc('id')->value('key');
        if ($activeKey) {
            foreach (DB::table('courses')->where('requires_placement_test', true)->pluck('id') as $courseId) {
                DB::table('course_placement_tests')->insert(['course_id' => $courseId, 'placement_test_key' => $activeKey, 'sort_order' => 1]);
            }
        }

        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('requires_placement_test'));
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('requires_placement_test')->default(false)->after('type');
        });
        DB::table('courses')->whereIn('id', DB::table('course_placement_tests')->select('course_id'))->update(['requires_placement_test' => true]);

        Schema::dropIfExists('course_placement_tests');

        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropIndex(['key']);
            $table->dropColumn(['key', 'sort_order']);
        });
    }
};

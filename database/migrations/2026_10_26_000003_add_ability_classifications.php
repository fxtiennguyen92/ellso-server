<?php

use App\Support\AbilityClassifications;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phân loại "Năng lực": mỗi phân loại của bài kiểm tra đầu vào (VD: Khả năng tiếp thu) trở thành
 * một phân loại lộ trình, giá trị = các mức độ. App không hỏi — lấy từ kết quả bài làm của học viên.
 *
 * - placement_dimensions.key / placement_levels.key: mã ổn định, giữ nguyên khi nhân bản bài kiểm tra
 *   → điều kiện lộ trình không bị mất khi đổi sang bản sao.
 * - classifications.type: question (hỏi học viên, như trước) | ability (năng lực, tự đồng bộ).
 * - courses.requires_placement_test: bắt buộc làm bài kiểm tra đầu vào trước bài học đầu tiên.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_dimensions', function (Blueprint $table) {
            $table->uuid('key')->nullable()->after('id')->index();
        });
        Schema::table('placement_levels', function (Blueprint $table) {
            $table->uuid('key')->nullable()->after('id')->index();
        });

        foreach (['placement_dimensions', 'placement_levels'] as $name) {
            DB::table($name)->whereNull('key')->orderBy('id')->each(
                fn ($row) => DB::table($name)->where('id', $row->id)->update(['key' => (string) Str::uuid()])
            );
        }

        Schema::table('classifications', function (Blueprint $table) {
            $table->string('type', 20)->default('question')->after('course_id')->index();
            $table->uuid('placement_dimension_key')->nullable()->after('type')->unique();
        });
        Schema::table('classification_options', function (Blueprint $table) {
            $table->uuid('placement_level_key')->nullable()->after('classification_id')->index();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('requires_placement_test')->default(false)->after('type');
        });

        // Tạo phân loại Năng lực cho bài kiểm tra đầu vào đã có
        AbilityClassifications::sync();
    }

    public function down(): void
    {
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('requires_placement_test'));

        DB::table('classifications')->where('type', 'ability')->delete();
        Schema::table('classification_options', function (Blueprint $table) {
            $table->dropIndex(['placement_level_key']);
            $table->dropColumn('placement_level_key');
        });
        Schema::table('classifications', function (Blueprint $table) {
            $table->dropUnique(['placement_dimension_key']);
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'placement_dimension_key']);
        });
        Schema::table('placement_levels', function (Blueprint $table) {
            $table->dropIndex(['key']);
            $table->dropColumn('key');
        });
        Schema::table('placement_dimensions', function (Blueprint $table) {
            $table->dropIndex(['key']);
            $table->dropColumn('key');
        });
    }
};

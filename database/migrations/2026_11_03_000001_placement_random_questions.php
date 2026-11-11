<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bài kiểm tra đầu vào rút ngẫu nhiên câu hỏi từ ngân hàng câu.
 *
 * - placement_tests.question_count: số câu mỗi lần làm (null = tất cả câu, theo thứ tự soạn).
 *   Có số → mỗi lần làm rút ngẫu nhiên N câu (ưu tiên đủ câu cho mọi năng lực), thứ tự ngẫu nhiên.
 * - placement_tests.shuffle_options: đảo thứ tự đáp án.
 * - Ngưỡng mức độ đổi từ điểm sang phần trăm (placement_levels.min_score → min_percent, 0–100):
 *   % = vị trí của tổng điểm trong khoảng điểm có thể đạt với CHÍNH các câu học viên nhận được
 *   → công bằng dù mỗi người nhận bộ câu khác nhau.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->unsignedSmallInteger('question_count')->nullable()->after('intro');
            $table->boolean('shuffle_options')->default(true)->after('question_count');
        });

        Schema::table('placement_levels', function (Blueprint $table) {
            $table->renameColumn('min_score', 'min_percent');
        });
    }

    public function down(): void
    {
        Schema::table('placement_levels', function (Blueprint $table) {
            $table->renameColumn('min_percent', 'min_score');
        });

        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropColumn(['question_count', 'shuffle_options']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phân loại lộ trình động.
 *
 * - classifications: tiêu chí phân loại (Nghề nghiệp, Mục tiêu học, Điểm yếu trong tư duy…)
 *     course_id = null  → dùng chung toàn hệ thống
 *     course_id = X     → riêng của khóa X
 * - classification_options: các giá trị của một phân loại
 * - course_classifications: khóa học dùng những phân loại nào, theo thứ tự (= thứ tự câu hỏi trên app)
 * - learning_path_options: điều kiện của lộ trình (nhiều giá trị / phân loại; không chọn = mọi giá trị)
 * - user_classification_options: câu trả lời gần nhất của học viên, dùng làm giá trị chọn sẵn khi hỏi lại
 *
 * Chuyển dữ liệu cũ: learner_groups → "Nghề nghiệp", learning_goals → "Mục tiêu học".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('question')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('classification_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classification_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('course_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classification_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['course_id', 'classification_id']);
        });

        Schema::create('learning_path_options', function (Blueprint $table) {
            $table->foreignId('learning_path_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classification_option_id')->constrained()->cascadeOnDelete();
            $table->primary(['learning_path_id', 'classification_option_id']);
        });

        Schema::create('user_classification_options', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classification_option_id')->constrained()->cascadeOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->primary(['user_id', 'classification_option_id']);
        });

        $this->migrateOldData();

        // Bỏ cấu trúc cũ
        Schema::table('learning_paths', function (Blueprint $table) {
            $table->index('course_id'); // giữ index cho FK course_id trước khi bỏ unique cũ
        });
        Schema::table('learning_paths', function (Blueprint $table) {
            $table->dropUnique('learning_paths_combo_unique');
            $table->dropConstrainedForeignId('learner_group_id');
            $table->dropConstrainedForeignId('learning_goal_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learner_group_id');
            $table->dropConstrainedForeignId('learning_goal_id');
        });
        Schema::dropIfExists('learner_groups');
        Schema::dropIfExists('learning_goals');
    }

    private function migrateOldData(): void
    {
        $now = now();

        $sources = [
            // bảng cũ        cột trên learning_paths / users   phân loại mới        câu hỏi
            ['learner_groups', 'learner_group_id', 'Nghề nghiệp', 'Bạn đang làm nghề gì?', 0],
            ['learning_goals', 'learning_goal_id', 'Mục tiêu học', 'Mục tiêu của bạn với khóa học này?', 1],
        ];

        foreach ($sources as [$oldTable, $column, $name, $question, $sort]) {
            if (! Schema::hasTable($oldTable)) {
                continue;
            }

            $classificationId = DB::table('classifications')->insertGetId([
                'name' => $name,
                'question' => $question,
                'sort_order' => $sort,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // giá trị cũ → giá trị mới
            $map = [];
            foreach (DB::table($oldTable)->orderBy('sort_order')->get() as $row) {
                $map[$row->id] = DB::table('classification_options')->insertGetId([
                    'classification_id' => $classificationId,
                    'name' => $row->name,
                    'description' => $row->description,
                    'sort_order' => $row->sort_order,
                    'is_active' => $row->is_active,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Điều kiện lộ trình + gắn phân loại vào khóa học
            $paths = DB::table('learning_paths')->whereNotNull($column)->get(['id', 'course_id', $column]);

            foreach ($paths as $path) {
                DB::table('learning_path_options')->insertOrIgnore([
                    'learning_path_id' => $path->id,
                    'classification_option_id' => $map[$path->{$column}],
                ]);
            }

            foreach ($paths->pluck('course_id')->unique() as $courseId) {
                DB::table('course_classifications')->insertOrIgnore([
                    'course_id' => $courseId,
                    'classification_id' => $classificationId,
                    'sort_order' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Hồ sơ học viên
            foreach (DB::table('users')->whereNotNull($column)->get(['id', $column]) as $user) {
                DB::table('user_classification_options')->insertOrIgnore([
                    'user_id' => $user->id,
                    'classification_option_id' => $map[$user->{$column}],
                    'answered_at' => $now,
                ]);
            }
        }
    }

    /** Rollback chỉ khôi phục cấu trúc cũ (không khôi phục dữ liệu phân loại). */
    public function down(): void
    {
        foreach (['learner_groups', 'learning_goals'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->string('slug')->unique();
                $t->text('description')->nullable();
                $t->unsignedInteger('sort_order')->default(0);
                $t->boolean('is_active')->default(true);
                $t->timestamps();
            });
        }

        Schema::table('learning_paths', function (Blueprint $table) {
            $table->foreignId('learner_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_goal_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('learner_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('learning_goal_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::dropIfExists('user_classification_options');
        Schema::dropIfExists('learning_path_options');
        Schema::dropIfExists('course_classifications');
        Schema::dropIfExists('classification_options');
        Schema::dropIfExists('classifications');
    }
};

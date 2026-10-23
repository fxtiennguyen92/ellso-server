<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Danh mục khóa học + 2 danh mục dùng chung toàn hệ thống:
 * Nhóm học viên (learner_groups) và Mục tiêu học (learning_goals).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('learner_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('learning_goals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Nhóm & mục tiêu mặc định trong hồ sơ học viên (dùng để app tự gợi ý lộ trình)
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('learner_group_id')->nullable()->after('bio')->constrained()->nullOnDelete();
            $table->foreignId('learning_goal_id')->nullable()->after('learner_group_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learner_group_id');
            $table->dropConstrainedForeignId('learning_goal_id');
        });

        Schema::dropIfExists('learning_goals');
        Schema::dropIfExists('learner_groups');
        Schema::dropIfExists('categories');
    }
};

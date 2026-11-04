<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Chương trình huấn luyện (offline, trước là "Khoá huấn luyện"): một chương trình diễn ra qua NHIỀU buổi
 *    (buổi 1 … buổi n). Số học viên tối đa tính cho cả chương trình (training_courses.capacity, bỏ capacity của
 *    từng buổi). Video trailer: upload file (không chuyển HLS) hoặc link YouTube. Học viên đăng ký cả chương trình.
 * 2. Workshop (online): một workshop = một buổi, lặp lại nhiều ngày / khung giờ. Số người tối đa tuỳ chọn, áp dụng
 *    cho từng buổi. Mỗi buổi có link họp (Zoom / Meet, admin dán tay). Học viên đăng ký từng buổi.
 * 3. Mẫu email "Link tham gia workshop".
 *
 * Đăng ký luôn cần gói thành viên còn hạn. price = 0: học viên có gói không trả thêm; price > 0: trả thêm (chờ cổng
 * thanh toán — trước mắt admin thêm người đăng ký trên CMS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->default(20)->after('price');
            $table->string('trailer_video_path')->nullable()->after('banner_path');
            $table->string('trailer_youtube_url', 300)->nullable()->after('trailer_video_path');
        });

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->dropColumn('capacity');
        });

        Schema::create('training_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10)->default('app'); // app | admin
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['training_course_id', 'user_id']);
        });

        Schema::create('workshops', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('summary', 300)->nullable();
            $table->string('banner_path');                      // 1280 × 720 (16:9)
            $table->unsignedBigInteger('price')->default(0);    // VND / người / buổi
            $table->unsignedInteger('capacity')->nullable();    // tối đa mỗi buổi; null = không giới hạn
            $table->foreignId('trainer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('content')->nullable();                // khối nội dung (như Chiến dịch)
            $table->string('status', 20)->default('draft')->index(); // draft | published | hidden
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workshop_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('meeting_url', 500)->nullable();     // link Zoom / Google Meet
            $table->string('note', 300)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('link_sent_at')->nullable();      // lần cuối gửi email link cho người đăng ký
            $table->unsignedInteger('link_sent_count')->nullable();
            $table->timestamps();

            $table->index(['workshop_id', 'starts_at']);
        });

        Schema::create('workshop_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10)->default('app'); // app | admin
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workshop_session_id', 'user_id']);
        });

        $now = now();
        DB::table('email_templates')->insert([
            'key' => 'workshop_link',
            'name' => 'Link tham gia workshop',
            'description' => 'Gửi khi admin bấm "Gửi link cho người đăng ký" ở lịch của workshop. Link họp, thời gian và tên workshop do hệ thống điền.',
            'subject' => 'Link tham gia "{workshop}" — {thoi_gian}',
            'preheader' => 'Lưu lại link để vào phòng đúng giờ nhé',
            'eyebrow' => 'Workshop online',
            'heading' => 'Hẹn gặp bạn tại {workshop}',
            'intro' => "Xin chào {ten},\nCảm ơn bạn đã đăng ký workshop **{workshop}**. Dưới đây là link tham gia buổi **{thoi_gian}**.",
            'steps_title' => 'Để buổi học suôn sẻ',
            'steps' => json_encode([
                'Vào phòng sớm 5 phút để kiểm tra micro và camera.',
                'Dùng tên thật (như trên tài khoản Ellso) khi vào phòng.',
                'Chuẩn bị sổ tay và câu hỏi bạn muốn trao đổi.',
            ], JSON_UNESCAPED_UNICODE),
            'after_text' => null,
            'note' => 'Link chỉ dành cho bạn — vui lòng không chia sẻ cho người khác.',
            'sign_off' => "Hẹn gặp bạn,\nĐội ngũ Ellso",
            'footer_reason' => 'Bạn nhận được email này vì đã đăng ký workshop trên Ellso bằng địa chỉ {email}.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('email_templates')->where('key', 'workshop_link')->delete();

        Schema::dropIfExists('workshop_registrations');
        Schema::dropIfExists('workshop_sessions');
        Schema::dropIfExists('workshops');
        Schema::dropIfExists('training_registrations');

        Schema::table('training_sessions', function (Blueprint $table) {
            $table->unsignedInteger('capacity')->default(20);
        });

        Schema::table('training_courses', function (Blueprint $table) {
            $table->dropColumn(['capacity', 'trailer_video_path', 'trailer_youtube_url']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Vé + điểm danh:
 * - training_registrations.ticket_code: mã vé của học viên (QR trên app; app giáo viên / kiểm soát viên quét để điểm danh — làm sau).
 * - training_courses.checkin_token: mã QR điểm danh của chương trình (in / chiếu ở lớp). Chỉ dùng được trong NGÀY có buổi học;
 *   học viên tự quét bằng app để điểm danh buổi hôm đó.
 * - training_attendances: mỗi học viên × mỗi buổi tối đa một lần điểm danh (giờ đến + cách điểm danh).
 * - workshop_registrations.attended_at: workshop online — người hướng dẫn tự điểm danh trong CMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_registrations', function (Blueprint $table) {
            $table->string('ticket_code', 16)->nullable()->unique()->after('user_id');
        });
        Schema::table('training_courses', function (Blueprint $table) {
            $table->string('checkin_token', 40)->nullable()->unique();
        });

        DB::table('training_registrations')->whereNull('ticket_code')->orderBy('id')->pluck('id')
            ->each(fn ($id) => DB::table('training_registrations')->where('id', $id)->update(['ticket_code' => self::code()]));
        DB::table('training_courses')->whereNull('checkin_token')->orderBy('id')->pluck('id')
            ->each(fn ($id) => DB::table('training_courses')->where('id', $id)->update(['checkin_token' => Str::random(32)]));

        Schema::create('training_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_in_at');
            $table->string('method', 12); // self_scan | staff_scan | manual
            $table->foreignId('checked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['training_registration_id', 'training_session_id'], 'training_attendance_unique');
        });

        Schema::table('workshop_registrations', function (Blueprint $table) {
            $table->timestamp('attended_at')->nullable();
            $table->foreignId('attendance_marked_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workshop_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attendance_marked_by_id');
            $table->dropColumn('attended_at');
        });
        Schema::dropIfExists('training_attendances');
        Schema::table('training_courses', function (Blueprint $table) {
            $table->dropUnique(['checkin_token']);
            $table->dropColumn('checkin_token');
        });
        Schema::table('training_registrations', function (Blueprint $table) {
            $table->dropUnique(['ticket_code']);
            $table->dropColumn('ticket_code');
        });
    }

    /** Mã vé 10 ký tự, bỏ chữ dễ nhầm (0/O, 1/I/L). */
    private static function code(): string
    {
        return collect(range(1, 10))->map(fn () => '23456789ABCDEFGHJKMNPQRSTUVWXYZ'[random_int(0, 30)])->join('');
    }
};

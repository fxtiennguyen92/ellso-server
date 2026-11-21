<?php

namespace App\Models;

use App\Enums\RegistrationSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Học viên đăng ký cả chương trình huấn luyện (mọi buổi). Có mã vé (ticket_code) để điểm danh bằng QR. */
class TrainingRegistration extends Model
{
    protected $fillable = ['training_course_id', 'user_id', 'ticket_code', 'source', 'note', 'created_by_id'];

    /** Tiền tố nội dung QR vé (app nhân viên quét vé → nhận ra đây là vé huấn luyện). */
    public const QR_PREFIX = 'ELLSO-TICKET:';

    protected static function booted(): void
    {
        static::creating(function (TrainingRegistration $r) {
            $r->ticket_code ??= self::newTicketCode();
        });
    }

    /** Mã vé 10 ký tự, không trùng, bỏ chữ dễ nhầm (0/O, 1/I/L). */
    public static function newTicketCode(): string
    {
        do {
            $code = collect(range(1, 10))->map(fn () => '23456789ABCDEFGHJKMNPQRSTUVWXYZ'[random_int(0, 30)])->join('');
        } while (self::query()->where('ticket_code', $code)->exists());

        return $code;
    }

    public function qrValue(): string
    {
        return self::QR_PREFIX.$this->ticket_code;
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(TrainingAttendance::class);
    }

    protected function casts(): array
    {
        return ['source' => RegistrationSource::class];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'training_course_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

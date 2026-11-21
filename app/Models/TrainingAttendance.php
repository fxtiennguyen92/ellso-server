<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Học viên có mặt ở một buổi huấn luyện: giờ đến + cách điểm danh. */
class TrainingAttendance extends Model
{
    /** Học viên tự quét QR của chương trình bằng app. */
    public const SELF_SCAN = 'self_scan';

    /** Giáo viên / kiểm soát viên quét vé (QR) của học viên (app nhân viên — làm sau). */
    public const STAFF_SCAN = 'staff_scan';

    /** Đánh dấu tay trong CMS. */
    public const MANUAL = 'manual';

    public const METHOD_LABELS = [
        self::SELF_SCAN => 'Tự quét QR',
        self::STAFF_SCAN => 'Quét vé',
        self::MANUAL => 'Điểm danh tay',
    ];

    protected $fillable = ['training_registration_id', 'training_session_id', 'checked_in_at', 'method', 'checked_by_id'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(TrainingRegistration::class, 'training_registration_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_id');
    }
}

<?php

namespace App\Models;

use App\Enums\RegistrationSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Học viên đăng ký một buổi workshop. attended_at: người hướng dẫn tự điểm danh trong CMS (workshop online). */
class WorkshopRegistration extends Model
{
    protected $fillable = ['workshop_session_id', 'user_id', 'source', 'note', 'created_by_id', 'attended_at', 'attendance_marked_by_id'];

    protected function casts(): array
    {
        return ['source' => RegistrationSource::class, 'attended_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkshopSession::class, 'workshop_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attendanceMarkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_marked_by_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}

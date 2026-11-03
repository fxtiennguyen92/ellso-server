<?php

namespace App\Models;

use App\Enums\RegistrationSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Học viên đăng ký cả chương trình huấn luyện (mọi buổi). */
class TrainingRegistration extends Model
{
    protected $fillable = ['training_course_id', 'user_id', 'source', 'note', 'created_by_id'];

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

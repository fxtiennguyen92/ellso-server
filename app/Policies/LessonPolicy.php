<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;

/** Thư viện bài học: admin toàn quyền, giảng viên chỉ bài do mình tạo. */
class LessonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, Lesson $lesson): bool
    {
        return $this->owns($user, $lesson);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function update(User $user, Lesson $lesson): bool
    {
        return $this->owns($user, $lesson);
    }

    public function delete(User $user, Lesson $lesson): bool
    {
        return $this->owns($user, $lesson);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Lesson $lesson): bool
    {
        return $this->owns($user, $lesson);
    }

    public function forceDelete(User $user, Lesson $lesson): bool
    {
        return $user->isAdmin();
    }

    /** Nhân bản: được xem bài là được nhân bản (bản sao thuộc về người nhân bản). */
    public function replicate(User $user, Lesson $lesson): bool
    {
        return $this->owns($user, $lesson);
    }

    private function owns(User $user, Lesson $lesson): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $lesson->owner_id === $user->id);
    }
}

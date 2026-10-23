<?php

namespace App\Policies;

use App\Enums\CourseType;
use App\Models\LearningPath;
use App\Models\User;

/** Lộ trình theo quyền trên khóa học chứa nó. */
class LearningPathPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, LearningPath $path): bool
    {
        return $user->can('update', $path->course);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function update(User $user, LearningPath $path): bool
    {
        return $user->can('update', $path->course);
    }

    /** Khóa học chung phải giữ lại lộ trình duy nhất của nó. */
    public function delete(User $user, LearningPath $path): bool
    {
        if ($path->course->type === CourseType::Single) {
            return false;
        }

        return $user->can('update', $path->course);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}

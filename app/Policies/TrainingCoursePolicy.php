<?php

namespace App\Policies;

use App\Models\TrainingCourse;
use App\Models\User;

/** Chỉ admin quản lý. */
class TrainingCoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, TrainingCourse $record): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, TrainingCourse $record): bool
    {
        return $user->isAdmin();
    }

    public function replicate(User $user, TrainingCourse $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, TrainingCourse $record): bool
    {
        return $user->isAdmin();
    }
}

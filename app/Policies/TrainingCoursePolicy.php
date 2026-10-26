<?php

namespace App\Policies;

use App\Models\TrainingCourse;
use App\Models\User;

/**
 * Admin quản lý toàn bộ. Giảng viên chỉ xem khoá mình hướng dẫn (thông tin + lịch tổ chức);
 * sau này: quản lý người đăng ký của khoá mình.
 */
class TrainingCoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, TrainingCourse $record): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $record->trainer_id === $user->id);
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

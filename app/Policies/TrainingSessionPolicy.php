<?php

namespace App\Policies;

use App\Models\TrainingSession;
use App\Models\User;

/** Lịch tổ chức: admin thêm / sửa / huỷ / xoá; giảng viên chỉ xem lịch khoá mình hướng dẫn. */
class TrainingSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, TrainingSession $record): bool
    {
        return $user->isAdmin() || $record->course?->trainer_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, TrainingSession $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, TrainingSession $record): bool
    {
        return $user->isAdmin();
    }
}

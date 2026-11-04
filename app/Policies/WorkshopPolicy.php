<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workshop;

/** Admin quản lý toàn bộ. Giảng viên chỉ xem workshop mình hướng dẫn (lịch + người đăng ký). */
class WorkshopPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, Workshop $record): bool
    {
        return $user->isAdmin() || ($user->isInstructor() && $record->trainer_id === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Workshop $record): bool
    {
        return $user->isAdmin();
    }

    public function replicate(User $user, Workshop $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Workshop $record): bool
    {
        return $user->isAdmin();
    }
}

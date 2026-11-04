<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkshopSession;

/** Lịch workshop: admin thêm / sửa / gửi link / huỷ / xoá; giảng viên chỉ xem lịch workshop mình hướng dẫn. */
class WorkshopSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, WorkshopSession $record): bool
    {
        return $user->isAdmin() || $record->workshop?->trainer_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, WorkshopSession $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, WorkshopSession $record): bool
    {
        return $user->isAdmin();
    }
}

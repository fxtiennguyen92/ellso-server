<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkshopRegistration;

/** Người đăng ký workshop: admin thêm / huỷ; giảng viên xem của workshop mình hướng dẫn. */
class WorkshopRegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, WorkshopRegistration $record): bool
    {
        return $user->isAdmin() || $record->session?->workshop?->trainer_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, WorkshopRegistration $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, WorkshopRegistration $record): bool
    {
        return $user->isAdmin();
    }
}

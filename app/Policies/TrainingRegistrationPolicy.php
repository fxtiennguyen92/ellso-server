<?php

namespace App\Policies;

use App\Models\TrainingRegistration;
use App\Models\User;

/** Người đăng ký chương trình huấn luyện: admin thêm / huỷ; giảng viên xem của chương trình mình hướng dẫn. */
class TrainingRegistrationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, TrainingRegistration $record): bool
    {
        return $user->isAdmin() || $record->course?->trainer_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, TrainingRegistration $record): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, TrainingRegistration $record): bool
    {
        return $user->isAdmin();
    }
}

<?php

namespace App\Policies;

use App\Models\TrainingLocation;
use App\Models\User;

/** Chỉ admin quản lý. Địa điểm đang dùng thì không xoá. */
class TrainingLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, TrainingLocation $record): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, TrainingLocation $record): bool
    {
        return $user->isAdmin();
    }

    public function replicate(User $user, TrainingLocation $record): bool
    {
        return $user->isAdmin();
    }

    /** Địa điểm đã dùng cho ngày tổ chức nào thì không xoá (để lịch cũ vẫn có địa chỉ). */
    public function delete(User $user, TrainingLocation $record): bool
    {
        return $user->isAdmin() && $record->sessions()->doesntExist();
    }
}

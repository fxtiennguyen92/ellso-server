<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserLog;

/** Nhật ký chỉ đọc, chỉ admin xem. */
class UserLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, UserLog $log): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UserLog $log): bool
    {
        return false;
    }

    public function delete(User $user, UserLog $log): bool
    {
        return false;
    }
}

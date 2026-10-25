<?php

namespace App\Policies;

use App\Models\PlacementTest;
use App\Models\User;

/** Bài kiểm tra đầu vào: chỉ admin. Bài đã có học viên làm thì không xoá được (ngừng dùng hoặc nhân bản). */
class PlacementTestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, PlacementTest $test): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, PlacementTest $test): bool
    {
        return $user->isAdmin();
    }

    public function replicate(User $user, PlacementTest $test): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, PlacementTest $test): bool
    {
        return $user->isAdmin() && $test->attempts()->doesntExist();
    }
}

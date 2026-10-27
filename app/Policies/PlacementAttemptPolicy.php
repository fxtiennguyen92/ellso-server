<?php

namespace App\Policies;

use App\Models\PlacementAttempt;
use App\Models\User;

/** Bài làm kiểm tra đầu vào: admin xem và xoá; không tạo / sửa trên CMS. */
class PlacementAttemptPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, PlacementAttempt $attempt): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PlacementAttempt $attempt): bool
    {
        return false;
    }

    public function delete(User $user, PlacementAttempt $attempt): bool
    {
        return $user->isAdmin();
    }
}

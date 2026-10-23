<?php

namespace App\Policies;

use App\Models\Classification;
use App\Models\User;

/**
 * Admin: tạo / sửa mọi phân loại (dùng chung và riêng), đổi phạm vi.
 * Giảng viên: chỉ phân loại riêng của khóa mình.
 */
class ClassificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function view(User $user, Classification $classification): bool
    {
        return $this->update($user, $classification);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isInstructor();
    }

    public function update(User $user, Classification $classification): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $classification->course_id !== null
            && $classification->course?->instructor_id === $user->id;
    }

    public function delete(User $user, Classification $classification): bool
    {
        return $this->update($user, $classification);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}

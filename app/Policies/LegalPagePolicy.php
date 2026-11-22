<?php

namespace App\Policies;

use App\Models\LegalPage;
use App\Models\User;

/** Điều khoản / Chính sách bảo mật: 2 trang cố định (migration tạo), chỉ admin sửa nội dung. */
class LegalPagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, LegalPage $page): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, LegalPage $page): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, LegalPage $page): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

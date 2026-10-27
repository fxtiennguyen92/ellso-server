<?php

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;

/** Thông số do code định nghĩa (migration); admin chỉ sửa giá trị. */
class SystemSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, SystemSetting $setting): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SystemSetting $setting): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, SystemSetting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

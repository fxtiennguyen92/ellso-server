<?php

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\User;

/** Mẫu email do code tạo; admin chỉ sửa nội dung. */
class EmailTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, EmailTemplate $template): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, EmailTemplate $template): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}

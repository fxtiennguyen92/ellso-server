<?php

namespace App\Policies;

use App\Models\PartnerEvent;
use App\Models\User;

/** Sự kiện ngoài lề: chỉ admin quản lý. */
class PartnerEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, PartnerEvent $event): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, PartnerEvent $event): bool
    {
        return $user->isAdmin();
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin();
    }

    public function replicate(User $user, PartnerEvent $event): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, PartnerEvent $event): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}

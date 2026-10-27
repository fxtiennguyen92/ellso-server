<?php

namespace App\Policies;

use App\Models\MembershipCampaign;
use App\Models\User;

class MembershipCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, MembershipCampaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, MembershipCampaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, MembershipCampaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}

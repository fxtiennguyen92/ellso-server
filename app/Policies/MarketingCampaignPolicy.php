<?php

namespace App\Policies;

use App\Models\MarketingCampaign;
use App\Models\User;

class MarketingCampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, MarketingCampaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, MarketingCampaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function reorder(User $user): bool
    {
        return $user->isAdmin();
    }

    public function replicate(User $user, MarketingCampaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, MarketingCampaign $campaign): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}

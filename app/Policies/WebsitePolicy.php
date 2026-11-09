<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Website $website): bool
    {
        return $user->tenant_id === $website->tenant_id;
    }

    public function create(User $user): bool
    {
        return $user->canManage();
    }

    public function manage(User $user, Website $website): bool
    {
        return $user->tenant_id === $website->tenant_id && $user->canManage();
    }

    public function delete(User $user, Website $website): bool
    {
        return $user->tenant_id === $website->tenant_id && $user->isOwner();
    }
}

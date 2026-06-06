<?php

namespace App\Policies;

use App\Models\User;

class StaffPolicy
{
    public function viewAny(User $actor): bool
    {
        return in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true);
    }

    public function create(User $actor): bool
    {
        return in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true);
    }

    public function deactivate(User $actor, User $target): bool
    {
        if ($actor->role === User::ROLE_SUPER_ADMIN) {
            return $target->role !== User::ROLE_OWNER;
        }

        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true)) {
            return false;
        }

        // tenant guard
        if ($actor->business_id !== $target->business_id) return false; // Փոխել salon_id-ից business_id

        if ($target->role === User::ROLE_OWNER) return false;

        if ($actor->id === $target->id) return false;

        return true;
    }

    public function activate(User $actor, User $target): bool
    {
        if ($actor->role === User::ROLE_SUPER_ADMIN) return true;

        if (!in_array($actor->role, [User::ROLE_OWNER, User::ROLE_MANAGER], true)) {
            return false;
        }

        if ($actor->business_id !== $target->business_id) return false; // Փոխել salon_id-ից business_id

        return true;
    }
}

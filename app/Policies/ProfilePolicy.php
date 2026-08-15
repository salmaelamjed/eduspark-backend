<?php

namespace App\Policies;

use App\Models\User;

class ProfilePolicy
{
    /**
     * Un utilisateur peut voir un profil public si le compte est actif,
     * ou si c'est son propre profil (même désactivé, pour se réactiver).
     */
    public function view(User $viewer, User $profile): bool
    {
        if ($viewer->id === $profile->id) {
            return true;
        }

        return $profile->is_active === true;
    }

    /**
     * Seul le propriétaire peut modifier son profil.
     */
    public function update(User $viewer, User $profile): bool
    {
        return $viewer->id === $profile->id;
    }

    /**
     * Un admin peut désactiver n'importe quel compte, un user seulement le sien.
     */
    public function deactivate(User $viewer, User $profile): bool
    {
        return $viewer->id === $profile->id || $viewer->role === 'admin';
    }
    public function adminDeactivate(User $admin, User $target): bool
{
    return $admin->role === 'admin';
}
}

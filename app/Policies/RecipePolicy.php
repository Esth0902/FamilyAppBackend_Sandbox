<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;

class RecipePolicy
{
    private function getHouseholdRole(User $user, ?int $householdId): ?string
    {
        if (!$householdId) {
            return null;
        }

        return $user->households()
            ->whereKey($householdId)
            ->value('household_user.role');
    }

    public function viewAny(User $user): bool
    {
        return $user->households()->exists();
    }

    public function view(User $user, Recipe $recipe): bool
    {
        if ((bool)$recipe->is_global) {
            return $this->viewAny($user);
        }

        return $this->getHouseholdRole($user, $recipe->household_id) !== null;
    }

    public function create(User $user, int $householdId): bool
    {
        return $this->getHouseholdRole($user, $householdId) !== null;
    }

    public function update(User $user, Recipe $recipe): bool
    {
        if ((bool)$recipe->is_global) {
            return false;
        }

        return $this->getHouseholdRole($user, $recipe->household_id) === 'parent';
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        if ((bool)$recipe->is_global) {
            return false;
        }

        return $this->getHouseholdRole($user, $recipe->household_id) === 'parent';
    }

    public function restore(User $user, Recipe $recipe): bool
    {
        return $this->delete($user, $recipe);
    }

    public function forceDelete(User $user, Recipe $recipe): bool
    {
        return $this->delete($user, $recipe);
    }
}

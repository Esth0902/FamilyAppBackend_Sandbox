<?php

namespace App\Policies;

use App\Models\MealPoll;
use App\Models\User;

class MealPollPolicy
{
    private function getRoleInHousehold(User $user, int $householdID): ?string
    {
        $household = $user->households()->where('households.id', $householdID)->first();
        return $household ? $household->pivot->role : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->households()->exists();
    }

    public function view(User $user, MealPoll $mealPoll): bool
    {
        return !is_null($this->getRoleInHousehold($user, $mealPoll->household_id));
    }

    public function create(User $user, int $householdID): bool
    {
        $role = $this->getRoleInHousehold($user, $householdID);
        return $role === User::ROLE_PARENT;
    }

    public function update(User $user, MealPoll $mealPoll): bool
    {
        return $this->getRoleInHousehold($user, $mealPoll->household_id) === User::ROLE_PARENT;
    }

    public function delete(User $user, MealPoll $mealPoll): bool
    {
        return $this->getRoleInHousehold($user, $mealPoll->household_id) === User::ROLE_PARENT;
    }

    public function restore(User $user, MealPoll $mealPoll): bool
    {
        return $this->getRoleInHousehold($user, $mealPoll->household_id) === User::ROLE_PARENT;
    }

    public function forceDelete(User $user, MealPoll $mealPoll): bool
    {
        return $this->getRoleInHousehold($user, $mealPoll->household_id) === User::ROLE_PARENT;
    }

    public function vote(User $user, MealPoll $mealPoll): bool
    {
        return !is_null($this->getRoleInHousehold($user, $mealPoll->household_id));
    }

    public function close(User $user, MealPoll $mealPoll): bool
    {
        return $this->getRoleInHousehold($user, $mealPoll->household_id) === User::ROLE_PARENT;
    }

    public function validate(User $user, MealPoll $mealPoll): bool
    {
        return $this->getRoleInHousehold($user, $mealPoll->household_id) === User::ROLE_PARENT;
    }
}

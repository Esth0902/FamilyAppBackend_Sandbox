<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Collection;

class GetHouseholdMembersAction
{
    /**
     * @return Collection<int, User>
     */
    public function execute(Household $household): Collection
    {
        return $household->users()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.must_change_password',
            ])
            ->orderByRaw("CASE WHEN household_user.role = ? THEN 0 ELSE 1 END", [User::ROLE_PARENT])
            ->orderBy('users.name')
            ->get();
    }
}


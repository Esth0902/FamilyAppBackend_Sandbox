<?php

namespace App\Http\Requests\HouseholdConnection;

use App\Models\User;

class UnlinkHouseholdRequest extends HouseholdConnectionRequest
{
    public function authorize(): bool
    {
        $user = $this->actor();
        if (!$user instanceof User) {
            return false;
        }

        return $this->isParentRole();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}


<?php

namespace App\Http\Requests\HouseholdConnection;

use App\Models\User;

class ShowHouseholdConnectionRequest extends HouseholdConnectionRequest
{
    public function authorize(): bool
    {
        return $this->actor() instanceof User;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

abstract class HouseholdAwareRequest extends FormRequest
{
    public function household(): Household
    {
        $household = $this->attributes->get('current_household');
        if ($household instanceof Household) {
            return $household;
        }

        throw ValidationException::withMessages([
            'household' => ['Le contexte foyer est introuvable pour cette requête.'],
        ]);
    }

    public function householdRole(): string
    {
        $role = $this->attributes->get('current_household_role');
        if (is_string($role) && $role !== '') {
            return $role;
        }

        return User::ROLE_CHILD;
    }
}


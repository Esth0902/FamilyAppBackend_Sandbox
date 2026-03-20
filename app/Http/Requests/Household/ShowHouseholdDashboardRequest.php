<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ShowHouseholdDashboardRequest extends FormRequest
{
    private ?Household $resolvedHousehold = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [];
    }

    public function actor(): ?User
    {
        $user = $this->user();
        return $user instanceof User ? $user : null;
    }

    public function household(): ?Household
    {
        if ($this->resolvedHousehold instanceof Household) {
            return $this->resolvedHousehold;
        }

        $user = $this->actor();
        if (!$user instanceof User) {
            return null;
        }

        $requestedHouseholdId = $this->resolveRequestedHouseholdId();
        $household = $requestedHouseholdId !== null
            ? $user->households()->where('households.id', $requestedHouseholdId)->first()
            : $user->households()->first();

        if ($requestedHouseholdId !== null && !$household instanceof Household) {
            throw ValidationException::withMessages([
                'household' => ['Foyer non accessible pour cet utilisateur.'],
            ]);
        }

        $this->resolvedHousehold = $household instanceof Household ? $household : null;
        return $this->resolvedHousehold;
    }

    private function resolveRequestedHouseholdId(): ?int
    {
        $rawValue = $this->header('X-Household-Id');
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return null;
        }

        $parsed = (int) $rawValue;
        return $parsed > 0 ? $parsed : null;
    }
}


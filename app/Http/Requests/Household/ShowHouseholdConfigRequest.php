<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ShowHouseholdConfigRequest extends FormRequest
{
    private ?Household $resolvedHousehold = null;

    public function authorize(): bool
    {
        return $this->actor() instanceof User;
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

    public function household(): Household
    {
        if ($this->resolvedHousehold instanceof Household) {
            return $this->resolvedHousehold;
        }

        $user = $this->actor();
        if (!$user instanceof User) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associé à cet utilisateur.'],
            ]);
        }

        $selectedHousehold = $this->resolveCurrentHouseholdFor($user);
        if ($selectedHousehold instanceof Household) {
            $selectedRole = (string) ($selectedHousehold->pivot->role ?? User::ROLE_CHILD);
            if ($selectedRole === User::ROLE_PARENT) {
                $this->resolvedHousehold = $selectedHousehold;
                return $this->resolvedHousehold;
            }
        }

        $firstParentHousehold = $user->households()->wherePivot('role', User::ROLE_PARENT)->first();
        if ($firstParentHousehold instanceof Household) {
            $this->resolvedHousehold = $firstParentHousehold;
            return $this->resolvedHousehold;
        }

        if ($selectedHousehold instanceof Household) {
            $this->resolvedHousehold = $selectedHousehold;
            return $this->resolvedHousehold;
        }

        throw ValidationException::withMessages([
            'household' => ['Aucun foyer trouvé pour cet utilisateur.'],
        ]);
    }

    private function resolveCurrentHouseholdFor(User $user): ?Household
    {
        $requestedHouseholdId = $this->resolveRequestedHouseholdId();
        if ($requestedHouseholdId !== null) {
            $requestedHousehold = $user->households()
                ->where('households.id', $requestedHouseholdId)
                ->first();

            if (!$requestedHousehold instanceof Household) {
                throw ValidationException::withMessages([
                    'household' => ['Foyer non accessible pour cet utilisateur.'],
                ]);
            }

            return $requestedHousehold;
        }

        return $user->households()->first();
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


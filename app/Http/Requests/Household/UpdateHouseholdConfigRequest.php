<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\HasHouseholdConfigurationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateHouseholdConfigRequest extends FormRequest
{
    use HasHouseholdConfigurationRules;

    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        $requestedHouseholdId = $this->resolveRequestedHouseholdId();
        if ($requestedHouseholdId !== null) {
            $household = $user->households()
                ->where('households.id', $requestedHouseholdId)
                ->first();

            if (!$household) {
                throw ValidationException::withMessages([
                    'household' => ['Foyer non accessible pour cet utilisateur.'],
                ]);
            }

            return (string) ($household->pivot->role ?? User::ROLE_CHILD) === User::ROLE_PARENT;
        }

        $household = $user->households()->first();
        if (!$household) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associé à cet utilisateur.'],
            ]);
        }

        return (string) ($household->pivot->role ?? User::ROLE_CHILD) === User::ROLE_PARENT;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return $this->householdConfigurationRules(true);
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

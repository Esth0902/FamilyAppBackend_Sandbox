<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\HasHouseholdConfigurationRules;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateHouseholdConfigRequest extends FormRequest
{
    use HasHouseholdConfigurationRules;

    private ?Household $resolvedHousehold = null;

    public function authorize(): bool
    {
        $user = $this->actor();
        if (!$user instanceof User) {
            return false;
        }

        $household = $this->resolveHouseholdFor($user);
        return (string) ($household->pivot->role ?? User::ROLE_CHILD) === User::ROLE_PARENT;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return $this->householdConfigurationRules(true);
    }

    public function actor(): ?User
    {
        $user = $this->user();
        return $user instanceof User ? $user : null;
    }

    public function actorOrFail(): User
    {
        $actor = $this->actor();
        if ($actor instanceof User) {
            return $actor;
        }

        throw ValidationException::withMessages([
            'user' => ['Utilisateur authentifié introuvable.'],
        ]);
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

        return $this->resolveHouseholdFor($user);
    }

    private function resolveHouseholdFor(User $user): Household
    {
        $requestedHouseholdId = $this->resolveRequestedHouseholdId();
        $household = $requestedHouseholdId !== null
            ? $user->households()->where('households.id', $requestedHouseholdId)->first()
            : $user->households()->first();

        if (!$household instanceof Household) {
            throw ValidationException::withMessages([
                'household' => ['Foyer non accessible pour cet utilisateur.'],
            ]);
        }

        $this->resolvedHousehold = $household;
        return $household;
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

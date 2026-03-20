<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ShowHouseholdMembersRequest extends FormRequest
{
    private ?Household $resolvedHousehold = null;
    private string $resolvedRole = User::ROLE_CHILD;

    public function authorize(): bool
    {
        $user = $this->actor();
        if (!$user instanceof User) {
            return false;
        }

        $this->resolveHouseholdFor($user);
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

    public function householdRole(): string
    {
        if ($this->resolvedHousehold instanceof Household) {
            return $this->resolvedRole;
        }

        $this->household();
        return $this->resolvedRole;
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
        $this->resolvedRole = (string) ($household->pivot->role ?? User::ROLE_CHILD);

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


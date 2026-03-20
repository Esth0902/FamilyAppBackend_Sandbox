<?php

namespace App\Http\Requests\HouseholdConnection;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

abstract class HouseholdConnectionRequest extends FormRequest
{
    private ?Household $resolvedHousehold = null;

    private ?string $resolvedRole = null;

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
            'user' => ['Utilisateur authentifie introuvable.'],
        ]);
    }

    public function household(): Household
    {
        $this->resolveContext();
        return $this->resolvedHousehold;
    }

    public function householdRole(): string
    {
        $this->resolveContext();
        return (string) $this->resolvedRole;
    }

    protected function isParentRole(): bool
    {
        return $this->householdRole() === User::ROLE_PARENT;
    }

    private function resolveContext(): void
    {
        if ($this->resolvedHousehold instanceof Household && is_string($this->resolvedRole)) {
            return;
        }

        $user = $this->actor();
        if (!$user instanceof User) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associe a cet utilisateur.'],
            ]);
        }

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


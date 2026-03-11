<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesHouseholdContext
{
    protected function resolveCurrentHousehold(User $user): ?Household
    {
        return $user->households()->first();
    }

    /**
     * @return array{0: Household, 1: string}
     */
    protected function resolveHouseholdWithRole(Request $request): array
    {
        $household = $request->user()->households()->first();

        if (!$household) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associe a cet utilisateur.'],
            ]);
        }

        $role = (string) ($household->pivot->role ?? User::ROLE_CHILD);

        return [$household, $role];
    }

    protected function ensureParentRole(string $role): void
    {
        if ($role !== User::ROLE_PARENT) {
            abort(403, 'Action reservee aux parents.');
        }
    }
}

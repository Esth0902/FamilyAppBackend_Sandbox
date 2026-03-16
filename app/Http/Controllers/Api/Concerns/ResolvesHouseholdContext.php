<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ResolvesHouseholdContext
{
    protected function resolveCurrentHousehold(User $user, ?Request $request = null): ?Household
    {
        if ($request) {
            $requestedHouseholdId = $this->resolveRequestedHouseholdId($request);
            if ($requestedHouseholdId !== null) {
                $requestedHousehold = $user->households()
                    ->where('households.id', $requestedHouseholdId)
                    ->first();

                if (! $requestedHousehold) {
                    throw ValidationException::withMessages([
                        'household' => ['Foyer non accessible pour cet utilisateur.'],
                    ]);
                }

                return $requestedHousehold;
            }
        }

        return $user->households()->first();
    }

    /**
     * @return array{0: Household, 1: string}
     */
    protected function resolveHouseholdWithRole(Request $request): array
    {
        $household = $this->resolveCurrentHousehold($request->user(), $request);

        if (!$household) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associe a cet utilisateur.'],
            ]);
        }

        $role = (string) ($household->pivot->role ?? User::ROLE_CHILD);

        return [$household, $role];
    }

    protected function resolveRequestedHouseholdId(Request $request): ?int
    {
        $rawValue = $request->header('X-Household-Id');
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return null;
        }

        $parsed = (int) $rawValue;
        return $parsed > 0 ? $parsed : null;
    }

    protected function ensureParentRole(string $role): void
    {
        if ($role !== User::ROLE_PARENT) {
            abort(403, 'Action reservee aux parents.');
        }
    }
}

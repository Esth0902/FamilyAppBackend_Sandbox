<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EnsureHouseholdContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) {
            return $next($request);
        }

        $requestedHouseholdId = $this->resolveRequestedHouseholdId($request);
        if ($requestedHouseholdId !== null) {
            $household = $user->households()
                ->where('households.id', $requestedHouseholdId)
                ->first();

            if (!$household) {
                throw ValidationException::withMessages([
                    'household' => ['Foyer non accessible pour cet utilisateur.'],
                ]);
            }
        } else {
            $household = $user->households()->first();
        }

        if (!$household) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associé à cet utilisateur.'],
            ]);
        }

        $role = (string) ($household->pivot->role ?? User::ROLE_CHILD);
        $request->attributes->set('current_household', $household);
        $request->attributes->set('current_household_role', $role);

        return $next($request);
    }

    private function resolveRequestedHouseholdId(Request $request): ?int
    {
        $rawValue = $request->header('X-Household-Id');
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return null;
        }

        $parsed = (int) $rawValue;

        return $parsed > 0 ? $parsed : null;
    }
}


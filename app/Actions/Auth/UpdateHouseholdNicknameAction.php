<?php

namespace App\Actions\Auth;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UpdateHouseholdNicknameAction
{
    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function execute(Request $request, Household $household): array
    {
        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:255'],
        ]);
        $nickname = trim((string) $validated['nickname']);

        if ($nickname === '') {
            throw ValidationException::withMessages([
                'nickname' => ['Le pseudo ne peut pas etre vide.'],
            ]);
        }

        /** @var User $user */
        $user = $request->user();

        $membership = $user->households()
            ->where('households.id', $household->id)
            ->first();

        if (!$membership) {
            return [
                'status' => 403,
                'payload' => [
                    'message' => 'Foyer non accessible.',
                ],
            ];
        }

        $user->households()->updateExistingPivot($household->id, [
            'nickname' => $nickname,
        ]);

        $updatedMembership = $user->households()
            ->where('households.id', $household->id)
            ->firstOrFail();

        return [
            'status' => 200,
            'payload' => [
                'message' => 'Pseudo du foyer mis a jour.',
                'household' => [
                    'id' => $updatedMembership->id,
                    'name' => $updatedMembership->name,
                    'role' => $updatedMembership->pivot->role,
                    'nickname' => $updatedMembership->pivot->nickname,
                ],
            ],
        ];
    }
}

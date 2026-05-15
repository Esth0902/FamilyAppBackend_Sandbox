<?php

namespace App\Actions\Auth;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DeleteAccountAction
{
    public function __construct(
        private readonly VerifyUserPasswordAction $verifyUserPasswordAction,
        private readonly DestroyUserAccountAction $destroyUserAccountAction,
    ) {
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function execute(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (!$this->verifyUserPasswordAction->execute($user, (string) $validated['current_password'])) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $blockedHouseholds = $this->resolveSoleParentBlockingHouseholds($user);
        if (count($blockedHouseholds) > 0) {
            return [
                'status' => 422,
                'payload' => [
                    'message' => "Tu es le dernier parent d'au moins un foyer. Désigne un nouveau parent ou supprime le foyer concerné avant de supprimer ton compte.",
                    'required_action' => 'define_new_parent_or_delete_household',
                    'blocked_households' => $blockedHouseholds,
                ],
            ];
        }

        $this->destroyUserAccountAction->execute($user);

        return [
            'status' => 200,
            'payload' => [
                'message' => 'Compte utilisateur supprimé définitivement.',
            ],
        ];
    }

    /**
     * @return array<int, array{
     *     household: array{id:int, name:string},
     *     candidate_members: array<int, array{id:int, name:string, role:string}>
     * }>
     */
    private function resolveSoleParentBlockingHouseholds(User $user): array
    {
        $parentHouseholds = $user->households()
            ->wherePivot('role', User::ROLE_PARENT)
            ->get(['households.id', 'households.name']);

        $blocked = [];

        /** @var Household $household */
        foreach ($parentHouseholds as $household) {
            $hasOtherParent = $household->users()
                ->wherePivot('role', User::ROLE_PARENT)
                ->where('users.id', '!=', (int) $user->id)
                ->exists();

            if ($hasOtherParent) {
                continue;
            }

            $candidateMembers = $household->users()
                ->select(['users.id', 'users.name'])
                ->where('users.id', '!=', (int) $user->id)
                ->orderByRaw("CASE WHEN household_user.role = ? THEN 0 ELSE 1 END", [User::ROLE_PARENT])
                ->orderBy('users.name')
                ->get()
                ->map(static fn (User $member): array => [
                    'id' => (int) $member->id,
                    'name' => (string) $member->name,
                    'role' => (string) ($member->pivot->role ?? User::ROLE_CHILD),
                ])
                ->values()
                ->all();

            $blocked[] = [
                'household' => [
                    'id' => (int) $household->id,
                    'name' => (string) $household->name,
                ],
                'candidate_members' => $candidateMembers,
            ];
        }

        return $blocked;
    }
}

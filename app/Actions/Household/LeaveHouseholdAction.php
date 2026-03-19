<?php

namespace App\Actions\Household;

use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
use App\Support\JsonUtf8Sanitizer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class LeaveHouseholdAction
{
    public function execute(Household $household, User $member): User
    {
        $isMember = $household->users()
            ->where('users.id', (int) $member->id)
            ->exists();
        if (!$isMember) {
            abort(404, 'Membre introuvable pour ce foyer.');
        }

        if (!$this->hasOtherParent($household, (int) $member->id)) {
            throw new HttpResponseException(response()->json(JsonUtf8Sanitizer::sanitize([
                'message' => 'Vous êtes le dernier parent de ce foyer. Désignez un nouveau parent ou supprimez ce foyer avant de le quitter.',
                'required_action' => 'define_new_parent_or_delete_household',
                'household' => [
                    'id' => (int) $household->id,
                    'name' => (string) $household->name,
                ],
                'candidate_members' => $this->resolveParentReplacementCandidates($household, (int) $member->id),
            ]), 422));
        }

        DB::transaction(function () use ($household, $member): void {
            $household->users()->detach((int) $member->id);
            BudgetSetting::query()
                ->where('household_id', (int) $household->id)
                ->where('user_id', (int) $member->id)
                ->delete();
        });

        return User::query()
            ->whereKey($member->id)
            ->with('households')
            ->firstOrFail();
    }

    private function hasOtherParent(Household $household, int $memberId): bool
    {
        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->where('users.id', '!=', $memberId)
            ->exists();
    }

    /**
     * @return array<int, array{id:int, name:string, role:string}>
     */
    private function resolveParentReplacementCandidates(Household $household, int $excludedUserId): array
    {
        return $household->users()
            ->select(['users.id', 'users.name'])
            ->where('users.id', '!=', $excludedUserId)
            ->orderByRaw("CASE WHEN household_user.role = ? THEN 0 ELSE 1 END", [User::ROLE_PARENT])
            ->orderBy('users.name')
            ->get()
            ->map(static fn(User $householdMember): array => [
                'id' => (int) $householdMember->id,
                'name' => (string) $householdMember->name,
                'role' => (string) ($householdMember->pivot->role ?? User::ROLE_CHILD),
            ])
            ->values()
            ->all();
    }
}

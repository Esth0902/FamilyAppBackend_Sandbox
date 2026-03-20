<?php

namespace App\Actions\Budget\Concerns;

use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait InteractsWithBudgetContext
{
    protected function ensureBudgetModuleEnabled(Household $household): void
    {
        $settings = HouseholdSetting::query()->where('household_id', $household->id)->first();
        if (!(bool) ($settings?->has_budget ?? false)) {
            abort(403, 'Le module budget est désactivé pour ce foyer.');
        }
    }

    protected function ensureParentRole(string $role): void
    {
        if ($role !== User::ROLE_PARENT) {
            abort(403, 'Action reservée aux parents.');
        }
    }

    protected function ensureChildRole(string $role): void
    {
        if ($role !== User::ROLE_CHILD) {
            abort(403, 'Action réservée aux enfants.');
        }
    }

    protected function ensureChildBelongsToHousehold(Household $household, int $userId): User
    {
        $member = $household
            ->users()
            ->select('users.id', 'users.name')
            ->where('users.id', $userId)
            ->first();

        if (!$member) {
            throw ValidationException::withMessages([
                'user_id' => ["Le membre sélectionné n'appartient pas au foyer."],
            ]);
        }

        $memberRole = (string) ($member->pivot->role ?? User::ROLE_CHILD);
        if ($memberRole !== User::ROLE_CHILD) {
            throw ValidationException::withMessages([
                'user_id' => ['Le budget argent de poche est réservé aux membres enfant.'],
            ]);
        }

        return $member;
    }

    protected function ensureTransactionBelongsToHousehold(PocketMoneyTransaction $transaction, Household $household): void
    {
        if ((int) $transaction->household_id !== (int) $household->id) {
            abort(404, 'Transaction introuvable.');
        }
    }

    protected function ensureTransactionIsAdjustment(PocketMoneyTransaction $transaction): void
    {
        $type = (string) $transaction->type;
        if ($type !== 'bonus' && $type !== 'penalty') {
            throw ValidationException::withMessages([
                'transaction' => ['Seuls les bonus et les pénalités peuvent être modifiés ou supprimés ici.'],
            ]);
        }
    }
}

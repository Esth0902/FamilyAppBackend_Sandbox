<?php

namespace App\Actions\Budget;

use App\Actions\Budget\Concerns\InteractsWithBudgetContext;
use App\Events\Budget\BudgetSettingUpdatedEvent;
use App\Models\BudgetSetting;
use App\Models\Household;
use Illuminate\Validation\ValidationException;

class UpdateBudgetSettingAction
{
    use InteractsWithBudgetContext;

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(Household $household, string $role, int $childUserId, array $payload): BudgetSetting
    {
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureChildBelongsToHousehold($household, $childUserId);

        $recurrence = (string) $payload['recurrence'];
        $resetDay = (int) $payload['reset_day'];
        if ($recurrence === 'weekly' && $resetDay > 7) {
            throw ValidationException::withMessages([
                'reset_day' => ['Le jour de réinitialisation hebdomadaire doit être compris entre 1 et 7.'],
            ]);
        }

        $allowAdvances = (bool) $payload['allow_advances'];
        $maxAdvanceAmount = $allowAdvances ? (float) $payload['max_advance_amount'] : 0.0;
        if ($allowAdvances && $maxAdvanceAmount <= 0) {
            throw ValidationException::withMessages([
                'max_advance_amount' => ["Le montant maximum d'avance doit être supérieur à 0 si les avances sont activées."],
            ]);
        }

        $setting = BudgetSetting::query()->updateOrCreate(
            ['household_id' => $household->id, 'user_id' => $childUserId],
            [
                'base_amount' => (float) $payload['base_amount'],
                'recurrence' => $recurrence,
                'reset_day' => $resetDay,
                'allow_advances' => $allowAdvances,
                'max_advance_amount' => $maxAdvanceAmount,
            ],
        );

        event(new BudgetSettingUpdatedEvent(
            householdId: (int) $household->id,
            userId: $childUserId,
            recurrence: (string) $setting->recurrence,
            resetDay: (int) $setting->reset_day,
            allowAdvances: (bool) $setting->allow_advances,
        ));

        return $setting;
    }
}

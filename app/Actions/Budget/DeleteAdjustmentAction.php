<?php

namespace App\Actions\Budget;

use App\Actions\Budget\Concerns\InteractsWithBudgetContext;
use App\Actions\Budget\Results\DeleteAdjustmentResult;
use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Events\Budget\BudgetAdjustmentDeletedEvent;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;

class DeleteAdjustmentAction
{
    use InteractsWithBudgetContext;

    private const TYPE_BONUS = 'bonus';

    public function execute(Household $household, string $role, PocketMoneyTransaction $transaction): DeleteAdjustmentResult
    {
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTransactionBelongsToHousehold($transaction, $household);
        $this->ensureTransactionIsAdjustment($transaction);

        $transactionId = (int) $transaction->id;
        $childUserId = (int) $transaction->user_id;
        $amount = abs((float) $transaction->amount);
        $type = (string) $transaction->type;
        $isBonus = $type === self::TYPE_BONUS;
        $justification = $this->normalizeNotificationJustification($transaction->comment);

        $transaction->delete();

        event(new BudgetAdjustmentDeletedEvent(
            householdId: (int) $household->id,
            transactionId: $transactionId,
            userId: $childUserId,
            amount: $amount,
            type: $type,
            justification: $justification,
        ));

        return new DeleteAdjustmentResult(
            message: $isBonus ? 'Bonus supprimé.' : 'Pénalité supprimée.',
            deletedTransactionId: $transactionId,
        );
    }

    private function normalizeNotificationJustification(mixed $comment): ?string
    {
        if ($comment instanceof BudgetComment) {
            return $comment->displayComment;
        }

        $value = trim((string) $comment);

        return $value === '' ? null : $value;
    }
}

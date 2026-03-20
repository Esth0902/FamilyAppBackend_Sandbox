<?php

namespace App\Actions\Budget;

use App\Actions\Budget\Concerns\InteractsWithBudgetContext;
use App\Actions\Budget\Results\BudgetTransactionResult;
use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Events\Budget\BudgetAdjustmentUpdatedEvent;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;
use Illuminate\Validation\ValidationException;

class UpdateAdjustmentAction
{
    use InteractsWithBudgetContext;

    private const TYPE_BONUS = 'bonus';

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(
        Household $household,
        string $role,
        PocketMoneyTransaction $transaction,
        array $payload
    ): BudgetTransactionResult {
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTransactionBelongsToHousehold($transaction, $household);
        $this->ensureTransactionIsAdjustment($transaction);

        if (
            !array_key_exists('type', $payload) &&
            !array_key_exists('amount', $payload) &&
            !array_key_exists('comment', $payload)
        ) {
            throw ValidationException::withMessages([
                'adjustment' => ['Aucune donnée fournie pour la mise à jour de l\'ajustement.'],
            ]);
        }

        $nextType = array_key_exists('type', $payload) && $payload['type'] !== null
            ? (string) $payload['type']
            : (string) $transaction->type;
        $nextAmount = array_key_exists('amount', $payload) && $payload['amount'] !== null
            ? abs((float) $payload['amount'])
            : abs((float) $transaction->amount);
        $nextComment = array_key_exists('comment', $payload)
            ? ((isset($payload['comment']) && trim((string) $payload['comment']) !== '')
                ? trim((string) $payload['comment'])
                : null)
            : $this->resolveDisplayComment($transaction->comment);

        $transaction->update([
            'type' => $nextType,
            'amount' => $nextAmount,
            'comment' => $nextComment,
        ]);
        $transaction->load('user:id,name');

        event(new BudgetAdjustmentUpdatedEvent(
            transaction: $transaction,
            householdId: (int) $household->id,
            userId: (int) $transaction->user_id,
            amount: $nextAmount,
            type: $nextType,
            justification: $this->normalizeNotificationJustification($nextComment),
        ));

        $isBonus = $nextType === self::TYPE_BONUS;

        return new BudgetTransactionResult(
            message: $isBonus ? 'Bonus mis à jour.' : 'Pénalité mise à jour.',
            transaction: $transaction,
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

    private function resolveDisplayComment(mixed $comment): ?string
    {
        if ($comment instanceof BudgetComment) {
            return $comment->displayComment;
        }

        $value = trim((string) $comment);

        return $value === '' ? null : $value;
    }
}

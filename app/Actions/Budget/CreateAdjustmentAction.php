<?php

namespace App\Actions\Budget;

use App\Actions\Budget\Concerns\InteractsWithBudgetContext;
use App\Actions\Budget\Results\BudgetTransactionResult;
use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Events\Budget\BudgetAdjustmentCreatedEvent;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;

class CreateAdjustmentAction
{
    use InteractsWithBudgetContext;

    private const STATUS_APPROVED = 'approved';
    private const TYPE_BONUS = 'bonus';

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(Household $household, string $role, array $payload): BudgetTransactionResult
    {
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);

        $child = $this->ensureChildBelongsToHousehold($household, (int) $payload['user_id']);
        $type = (string) $payload['type'];
        $amount = abs((float) $payload['amount']);
        $isBonus = $type === self::TYPE_BONUS;

        $transaction = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'amount' => $amount,
            'type' => $type,
            'status' => self::STATUS_APPROVED,
            'comment' => isset($payload['comment']) && trim((string) $payload['comment']) !== ''
                ? trim((string) $payload['comment'])
                : ($isBonus ? 'Bonus attribué par le parent.' : 'Pénalité attribuée par le parent.'),
        ]);
        $transaction->load('user:id,name');

        event(new BudgetAdjustmentCreatedEvent(
            transaction: $transaction,
            householdId: (int) $household->id,
            userId: (int) $child->id,
            amount: $amount,
            type: $type,
            justification: $this->normalizeNotificationJustification($transaction->comment),
        ));

        return new BudgetTransactionResult(
            message: $isBonus ? 'Bonus enregistré.' : 'Pénalité enregistrée.',
            transaction: $transaction,
            statusCode: 201,
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

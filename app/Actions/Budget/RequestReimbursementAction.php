<?php

namespace App\Actions\Budget;

use App\Actions\Budget\Concerns\InteractsWithBudgetContext;
use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Events\Budget\AdvanceRequestedEvent;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;
use App\Models\User;

class RequestReimbursementAction
{
    use InteractsWithBudgetContext;

    private const TYPE_ADVANCE = 'advance';
    private const STATUS_PENDING = 'pending';
    private const REQUEST_KIND_REIMBURSEMENT = 'reimbursement';

    public function execute(
        Household $household,
        string $role,
        User $currentUser,
        float $amount,
        string $comment
    ): PocketMoneyTransaction {
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureChildRole($role);

        $setting = BudgetSetting::query()
            ->where('household_id', $household->id)
            ->where('user_id', $currentUser->id)
            ->first();
        if (!$setting) {
            abort(403, 'Aucun paramètre budget configuré pour cet enfant.');
        }

        $requestedAmount = abs($amount);
        $cleanComment = trim($comment);

        $transaction = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $currentUser->id,
            'amount' => $requestedAmount,
            'type' => self::TYPE_ADVANCE,
            'status' => self::STATUS_PENDING,
            'comment' => new BudgetComment($cleanComment, self::REQUEST_KIND_REIMBURSEMENT),
        ]);
        $transaction->load('user:id,name');

        event(new AdvanceRequestedEvent(
            transaction: $transaction,
            householdId: (int) $household->id,
            requesterUserId: (int) $currentUser->id,
            requesterName: (string) ($currentUser->name ?? 'Un enfant'),
            amount: $requestedAmount,
            requestKind: self::REQUEST_KIND_REIMBURSEMENT,
            comment: $cleanComment,
        ));

        return $transaction;
    }
}

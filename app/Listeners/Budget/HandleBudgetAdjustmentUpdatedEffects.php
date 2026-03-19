<?php

namespace App\Listeners\Budget;

use App\Events\Budget\BudgetAdjustmentUpdatedEvent;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;

class HandleBudgetAdjustmentUpdatedEffects
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(BudgetAdjustmentUpdatedEvent $event): void
    {
        $isBonus = $event->type === 'bonus';

        $this->notificationService->notifyUser(
            $event->userId,
            $event->householdId,
            'budget_adjustment_updated',
            $isBonus ? 'Bonus mis à jour' : 'Pénalité mise à jour',
            $isBonus
                ? sprintf('Un bonus de %0.2f € a été mis à jour sur ton budget.', $event->amount)
                : sprintf('Une pénalité de %0.2f € a été mise à jour sur ton budget.', $event->amount),
            [
                'transaction_id' => (int) $event->transaction->id,
                'user_id' => $event->userId,
                'amount' => $event->amount,
                'type' => $event->type,
                'status' => (string) $event->transaction->status,
                'justification' => $event->justification,
            ],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'budget',
            type: 'adjustment.updated',
            payload: [
                'transaction_id' => (int) $event->transaction->id,
                'user_id' => $event->userId,
                'amount' => $event->amount,
                'adjustment_type' => $event->type,
                'household_id' => $event->householdId,
            ],
        );
    }
}

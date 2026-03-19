<?php

namespace App\Listeners\Budget;

use App\Events\Budget\BudgetAdjustmentDeletedEvent;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;

class HandleBudgetAdjustmentDeletedEffects
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(BudgetAdjustmentDeletedEvent $event): void
    {
        $isBonus = $event->type === 'bonus';

        $this->notificationService->notifyUser(
            $event->userId,
            $event->householdId,
            'budget_adjustment_deleted',
            $isBonus ? 'Bonus supprimé' : 'Pénalité supprimée',
            $isBonus
                ? sprintf('Un bonus de %0.2f € a été supprimé de ton budget.', $event->amount)
                : sprintf('Une pénalité de %0.2f € a été supprimée de ton budget.', $event->amount),
            [
                'transaction_id' => $event->transactionId,
                'user_id' => $event->userId,
                'amount' => $event->amount,
                'type' => $event->type,
                'justification' => $event->justification,
            ],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'budget',
            type: 'adjustment.deleted',
            payload: [
                'transaction_id' => $event->transactionId,
                'user_id' => $event->userId,
                'amount' => $event->amount,
                'adjustment_type' => $event->type,
                'household_id' => $event->householdId,
            ],
        );
    }
}

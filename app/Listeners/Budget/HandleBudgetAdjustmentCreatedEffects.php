<?php

namespace App\Listeners\Budget;

use App\Events\Budget\BudgetAdjustmentCreatedEvent;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;

class HandleBudgetAdjustmentCreatedEffects
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(BudgetAdjustmentCreatedEvent $event): void
    {
        $isBonus = $event->type === 'bonus';

        $this->notificationService->notifyUser(
            $event->userId,
            $event->householdId,
            'budget_adjustment_added',
            $isBonus ? 'Bonus attribué' : 'Pénalité attribuée',
            $isBonus
                ? sprintf('Un bonus de %0.2f € a été ajouté à ton budget.', $event->amount)
                : sprintf('Une pénalité de %0.2f € a été appliquée à ton budget.', $event->amount),
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
            type: 'adjustment.created',
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

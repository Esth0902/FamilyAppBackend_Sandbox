<?php

namespace App\Listeners\Budget;

use App\Events\Budget\NegativeBalanceCarriedOverEvent;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;

class HandleNegativeBalanceCarriedOverEffects
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(NegativeBalanceCarriedOverEvent $event): void
    {
        $this->notificationService->notifyUser(
            $event->userId,
            $event->householdId,
            'budget_negative_carried_over',
            'Solde reporté',
            sprintf('Un solde de %0.2f € sera déduit du prochain budget.', $event->carryAmount),
            [
                'user_id' => $event->userId,
                'carry_amount' => $event->carryAmount,
                'period_start' => $event->periodStart,
                'period_end' => $event->periodEnd,
                'next_period_start' => $event->nextPeriodStart,
                'reset_adjustments_count' => $event->resetAdjustmentsCount,
            ],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'budget',
            type: 'payment.negative_carried',
            payload: [
                'user_id' => $event->userId,
                'carry_amount' => $event->carryAmount,
                'period_start' => $event->periodStart,
                'period_end' => $event->periodEnd,
                'next_period_start' => $event->nextPeriodStart,
                'reset_adjustments_count' => $event->resetAdjustmentsCount,
                'household_id' => $event->householdId,
            ],
        );
    }
}

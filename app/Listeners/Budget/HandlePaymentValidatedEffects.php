<?php

namespace App\Listeners\Budget;

use App\Events\Budget\PaymentValidatedEvent;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;

class HandlePaymentValidatedEffects
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(PaymentValidatedEvent $event): void
    {
        $this->notificationService->notifyUser(
            $event->userId,
            $event->householdId,
            'budget_payment_validated',
            'Paiement validé',
            sprintf('Ton paiement de %0.2f a été validé.', abs($event->amount)),
            [
                'transaction_id' => (int) $event->transaction->id,
                'amount' => abs($event->amount),
                'status' => (string) $event->transaction->status,
                'reset_adjustments_count' => $event->resetAdjustmentsCount,
            ],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'budget',
            type: 'payment.validated',
            payload: [
                'transaction_id' => (int) $event->transaction->id,
                'user_id' => $event->userId,
                'amount' => abs($event->amount),
                'reset_adjustments_count' => $event->resetAdjustmentsCount,
                'household_id' => $event->householdId,
            ],
        );
    }
}

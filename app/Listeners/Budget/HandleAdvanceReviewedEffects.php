<?php

namespace App\Listeners\Budget;

use App\Events\Budget\AdvanceReviewedEvent;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;

class HandleAdvanceReviewedEffects
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(AdvanceReviewedEvent $event): void
    {
        $this->notificationService->notifyUser(
            $event->userId,
            $event->householdId,
            $event->isReimbursement ? 'budget_reimbursement_reviewed' : 'budget_advance_reviewed',
            $event->approved
                ? ($event->isReimbursement ? 'Demande de remboursement approuvée' : 'Demande d\'avance approuvée')
                : ($event->isReimbursement ? 'Demande de remboursement refusée' : 'Demande d\'avance refusée'),
            $event->approved
                ? ($event->isReimbursement
                    ? sprintf('Ta demande de remboursement de %0.2f € est approuvée%s.', abs($event->amount), $event->modeText)
                    : sprintf('Ta demande d\'avance de %0.2f € a été approuvée.', abs($event->amount)))
                : sprintf('Ta demande de %0.2f € a été refusée.', abs($event->amount)),
            [
                'transaction_id' => (int) $event->transaction->id,
                'user_id' => $event->userId,
                'amount' => abs($event->amount),
                'status' => $event->status,
                'request_kind' => $event->requestKind,
                'payout_mode' => $event->payoutMode,
                'justification' => $event->justification,
            ],
        );

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'budget',
            type: 'advance.reviewed',
            payload: [
                'transaction_id' => (int) $event->transaction->id,
                'user_id' => $event->userId,
                'amount' => abs($event->amount),
                'status' => $event->status,
                'request_kind' => $event->requestKind,
                'payout_mode' => $event->payoutMode,
                'household_id' => $event->householdId,
            ],
        );
    }
}

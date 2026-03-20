<?php

namespace App\Listeners\Realtime;

use App\Events\Budget\AdvanceRequestedEvent;
use App\Services\RealtimePublisher;

class BroadcastAdvanceRealtimeListener
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function handle(AdvanceRequestedEvent $event): void
    {
        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'budget',
            type: 'advance.requested',
            payload: [
                'transaction_id' => (int) $event->transaction->id,
                'user_id' => $event->requesterUserId,
                'amount' => abs($event->amount),
                'status' => (string) $event->transaction->status,
                'request_kind' => (string) $event->requestKind,
                'household_id' => $event->householdId,
            ],
        );
    }
}


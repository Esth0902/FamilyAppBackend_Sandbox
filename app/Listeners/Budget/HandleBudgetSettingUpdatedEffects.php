<?php

namespace App\Listeners\Budget;

use App\Events\Budget\BudgetSettingUpdatedEvent;
use App\Services\RealtimePublisher;

class HandleBudgetSettingUpdatedEffects
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function handle(BudgetSettingUpdatedEvent $event): void
    {
        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'budget',
            type: 'setting.updated',
            payload: [
                'user_id' => $event->userId,
                'recurrence' => $event->recurrence,
                'reset_day' => $event->resetDay,
                'allow_advances' => $event->allowAdvances,
                'household_id' => $event->householdId,
            ],
        );
    }
}

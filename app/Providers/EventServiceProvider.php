<?php

namespace App\Providers;

use App\Events\Budget\AdvanceRequestedEvent;
use App\Events\Budget\AdvanceReviewedEvent;
use App\Events\Budget\BudgetAdjustmentCreatedEvent;
use App\Events\Budget\BudgetAdjustmentDeletedEvent;
use App\Events\Budget\BudgetAdjustmentUpdatedEvent;
use App\Events\Budget\BudgetSettingUpdatedEvent;
use App\Events\Budget\NegativeBalanceCarriedOverEvent;
use App\Events\Budget\PaymentValidatedEvent;
use App\Listeners\Budget\HandleAdvanceReviewedEffects;
use App\Listeners\Budget\HandleBudgetAdjustmentCreatedEffects;
use App\Listeners\Budget\HandleBudgetAdjustmentDeletedEffects;
use App\Listeners\Budget\HandleBudgetAdjustmentUpdatedEffects;
use App\Listeners\Budget\HandleBudgetSettingUpdatedEffects;
use App\Listeners\Budget\HandleNegativeBalanceCarriedOverEffects;
use App\Listeners\Budget\HandlePaymentValidatedEffects;
use App\Listeners\Notifications\CreateAdvanceNotificationListener;
use App\Listeners\Realtime\BroadcastAdvanceRealtimeListener;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        AdvanceRequestedEvent::class => [
            CreateAdvanceNotificationListener::class,
            BroadcastAdvanceRealtimeListener::class,
        ],
        BudgetSettingUpdatedEvent::class => [
            HandleBudgetSettingUpdatedEffects::class,
        ],
        PaymentValidatedEvent::class => [
            HandlePaymentValidatedEffects::class,
        ],
        NegativeBalanceCarriedOverEvent::class => [
            HandleNegativeBalanceCarriedOverEffects::class,
        ],
        BudgetAdjustmentCreatedEvent::class => [
            HandleBudgetAdjustmentCreatedEffects::class,
        ],
        BudgetAdjustmentUpdatedEvent::class => [
            HandleBudgetAdjustmentUpdatedEffects::class,
        ],
        BudgetAdjustmentDeletedEvent::class => [
            HandleBudgetAdjustmentDeletedEffects::class,
        ],
        AdvanceReviewedEvent::class => [
            HandleAdvanceReviewedEffects::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

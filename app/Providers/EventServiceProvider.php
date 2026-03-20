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
use App\Events\Calendar\CalendarEventCreatedEvent;
use App\Events\Calendar\CalendarEventDeletedEvent;
use App\Events\Calendar\CalendarEventUpdatedEvent;
use App\Events\Calendar\EventParticipationConfirmedEvent;
use App\Events\Calendar\MealPlanAttendanceConfirmedEvent;
use App\Events\Calendar\MealPlanDeletedEvent;
use App\Events\Calendar\MealPlanStoredEvent;
use App\Events\Calendar\MealPlanUpdatedEvent;
use App\Events\Tasks\TaskInstanceCreatedEvent;
use App\Events\Tasks\TaskInstanceUpdatedEvent;
use App\Events\Tasks\TaskInstanceValidatedEvent;
use App\Events\Tasks\TaskReassignmentRequestedEvent;
use App\Events\Tasks\TaskTemplateCreatedEvent;
use App\Events\Tasks\TaskTemplateDeletedEvent;
use App\Events\Tasks\TaskTemplateUpdatedEvent;
use App\Listeners\Budget\HandleAdvanceReviewedEffects;
use App\Listeners\Budget\HandleBudgetAdjustmentCreatedEffects;
use App\Listeners\Budget\HandleBudgetAdjustmentDeletedEffects;
use App\Listeners\Budget\HandleBudgetAdjustmentUpdatedEffects;
use App\Listeners\Budget\HandleBudgetSettingUpdatedEffects;
use App\Listeners\Budget\HandleNegativeBalanceCarriedOverEffects;
use App\Listeners\Budget\HandlePaymentValidatedEffects;
use App\Listeners\Calendar\HandleCalendarEventCreatedEffects;
use App\Listeners\Calendar\HandleCalendarEventDeletedEffects;
use App\Listeners\Calendar\HandleCalendarEventUpdatedEffects;
use App\Listeners\Calendar\HandleEventParticipationConfirmedEffects;
use App\Listeners\Calendar\HandleMealPlanAttendanceConfirmedEffects;
use App\Listeners\Calendar\HandleMealPlanDeletedEffects;
use App\Listeners\Calendar\HandleMealPlanStoredEffects;
use App\Listeners\Calendar\HandleMealPlanUpdatedEffects;
use App\Listeners\Notifications\CreateAdvanceNotificationListener;
use App\Listeners\Realtime\BroadcastAdvanceRealtimeListener;
use App\Listeners\Tasks\HandleTaskInstanceCreatedEffects;
use App\Listeners\Tasks\HandleTaskInstanceUpdatedEffects;
use App\Listeners\Tasks\HandleTaskInstanceValidatedEffects;
use App\Listeners\Tasks\HandleTaskReassignmentRequestedEffects;
use App\Listeners\Tasks\HandleTaskTemplateCreatedEffects;
use App\Listeners\Tasks\HandleTaskTemplateDeletedEffects;
use App\Listeners\Tasks\HandleTaskTemplateUpdatedEffects;
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
        TaskTemplateCreatedEvent::class => [
            HandleTaskTemplateCreatedEffects::class,
        ],
        TaskTemplateUpdatedEvent::class => [
            HandleTaskTemplateUpdatedEffects::class,
        ],
        TaskTemplateDeletedEvent::class => [
            HandleTaskTemplateDeletedEffects::class,
        ],
        TaskInstanceCreatedEvent::class => [
            HandleTaskInstanceCreatedEffects::class,
        ],
        TaskInstanceUpdatedEvent::class => [
            HandleTaskInstanceUpdatedEffects::class,
        ],
        TaskInstanceValidatedEvent::class => [
            HandleTaskInstanceValidatedEffects::class,
        ],
        TaskReassignmentRequestedEvent::class => [
            HandleTaskReassignmentRequestedEffects::class,
        ],
        CalendarEventCreatedEvent::class => [
            HandleCalendarEventCreatedEffects::class,
        ],
        CalendarEventUpdatedEvent::class => [
            HandleCalendarEventUpdatedEffects::class,
        ],
        CalendarEventDeletedEvent::class => [
            HandleCalendarEventDeletedEffects::class,
        ],
        MealPlanStoredEvent::class => [
            HandleMealPlanStoredEffects::class,
        ],
        MealPlanUpdatedEvent::class => [
            HandleMealPlanUpdatedEffects::class,
        ],
        MealPlanDeletedEvent::class => [
            HandleMealPlanDeletedEffects::class,
        ],
        MealPlanAttendanceConfirmedEvent::class => [
            HandleMealPlanAttendanceConfirmedEffects::class,
        ],
        EventParticipationConfirmedEvent::class => [
            HandleEventParticipationConfirmedEffects::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}

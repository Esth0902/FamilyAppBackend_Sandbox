<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\MealPlanUpdatedEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleMealPlanUpdatedEffects implements ShouldQueue
{
    use InteractsWithCalendarAudience;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(MealPlanUpdatedEvent $event): void
    {
        $mealPlan = $event->mealPlan;
        $mealTypeLabel = $this->mealTypeLabel((string) $mealPlan->meal_type);
        $dateLabel = (string) optional($mealPlan->date)->toDateString();

        $memberIds = $this->resolveHouseholdMemberIds($event->householdId, $event->actorUserId);
        if (!empty($memberIds)) {
            $this->notificationService->notifyUsers(
                userIds: $memberIds,
                householdId: $event->householdId,
                type: 'calendar_meal_plan_updated',
                title: 'Repas planifié modifié',
                body: sprintf('Le repas %s du %s a été modifié.', $mealTypeLabel, $dateLabel),
                data: [
                    'meal_plan_id' => (int) $mealPlan->id,
                    'date' => $dateLabel,
                    'meal_type' => (string) $mealPlan->meal_type,
                    'change' => 'updated',
                    'actor_user_id' => $event->actorUserId,
                    'actor_name' => $event->actorName,
                ],
            );
        }

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'calendar',
            type: 'meal_plan.updated',
            payload: [
                'meal_plan_id' => (int) $mealPlan->id,
                'date' => $dateLabel,
                'meal_type' => (string) $mealPlan->meal_type,
                'household_id' => $event->householdId,
            ],
        );
    }
}

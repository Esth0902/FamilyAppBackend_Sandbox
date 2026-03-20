<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\MealPlanStoredEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleMealPlanStoredEffects implements ShouldQueue
{
    use InteractsWithCalendarAudience;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(MealPlanStoredEvent $event): void
    {
        $mealPlan = $event->mealPlan;
        $mealTypeLabel = $this->mealTypeLabel((string) $mealPlan->meal_type);
        $dateLabel = (string) optional($mealPlan->date)->toDateString();
        $wasCreated = $event->wasRecentlyCreated;

        $memberIds = $this->resolveHouseholdMemberIds($event->householdId, $event->actorUserId);
        if (!empty($memberIds)) {
            $this->notificationService->notifyUsers(
                userIds: $memberIds,
                householdId: $event->householdId,
                type: $wasCreated ? 'calendar_meal_plan_added' : 'calendar_meal_plan_updated',
                title: $wasCreated ? 'Repas planifié ajouté' : 'Repas planifié modifié',
                body: sprintf(
                    'Le repas %s du %s a été %s.',
                    $mealTypeLabel,
                    $dateLabel,
                    $wasCreated ? 'ajouté' : 'modifié'
                ),
                data: [
                    'meal_plan_id' => (int) $mealPlan->id,
                    'date' => $dateLabel,
                    'meal_type' => (string) $mealPlan->meal_type,
                    'change' => $wasCreated ? 'added' : 'updated',
                    'actor_user_id' => $event->actorUserId,
                    'actor_name' => $event->actorName,
                ],
            );
        }

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'calendar',
            type: $wasCreated ? 'meal_plan.created' : 'meal_plan.updated',
            payload: [
                'meal_plan_id' => (int) $mealPlan->id,
                'date' => $dateLabel,
                'meal_type' => (string) $mealPlan->meal_type,
                'household_id' => $event->householdId,
            ],
        );
    }
}

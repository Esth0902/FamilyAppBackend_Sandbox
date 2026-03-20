<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\MealPlanDeletedEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleMealPlanDeletedEffects implements ShouldQueue
{
    use InteractsWithCalendarAudience;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(MealPlanDeletedEvent $event): void
    {
        $mealTypeLabel = $this->mealTypeLabel($event->mealType);
        $dateLabel = (string) $event->mealPlanDate;

        $memberIds = $this->resolveHouseholdMemberIds($event->householdId, $event->actorUserId);
        if (!empty($memberIds)) {
            $this->notificationService->notifyUsers(
                userIds: $memberIds,
                householdId: $event->householdId,
                type: 'calendar_meal_plan_deleted',
                title: 'Repas planifié supprimé',
                body: sprintf('Le repas %s du %s a été supprimé.', $mealTypeLabel, $dateLabel),
                data: [
                    'meal_plan_id' => $event->mealPlanId,
                    'date' => $dateLabel,
                    'meal_type' => $event->mealType,
                    'change' => 'deleted',
                    'actor_user_id' => $event->actorUserId,
                    'actor_name' => $event->actorName,
                ],
            );
        }

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'calendar',
            type: 'meal_plan.deleted',
            payload: [
                'meal_plan_id' => $event->mealPlanId,
                'date' => $dateLabel,
                'meal_type' => $event->mealType,
                'household_id' => $event->householdId,
            ],
        );
    }
}

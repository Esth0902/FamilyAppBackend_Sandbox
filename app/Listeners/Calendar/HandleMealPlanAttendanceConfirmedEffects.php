<?php

namespace App\Listeners\Calendar;

use App\Events\Calendar\MealPlanAttendanceConfirmedEvent;
use App\Listeners\Calendar\Concerns\InteractsWithCalendarAudience;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleMealPlanAttendanceConfirmedEffects implements ShouldQueue
{
    use InteractsWithCalendarAudience;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function handle(MealPlanAttendanceConfirmedEvent $event): void
    {
        $attendance = $event->attendance;
        $mealPlan = $event->mealPlan;
        $status = (string) $attendance->status;

        if (in_array($status, ['not_home', 'later'], true)) {
            $parentIds = $this->resolveParentUserIds($event->householdId, $event->actorUserId);
            if (!empty($parentIds)) {
                $statusLabel = $status === 'not_home' ? 'ne mangera pas à la maison' : 'mangera plus tard';
                $this->notificationService->notifyUsers(
                    userIds: $parentIds,
                    householdId: $event->householdId,
                    type: 'calendar_meal_presence_updated',
                    title: 'Présence repas mise à jour',
                    body: sprintf(
                        '%s a indiqué qu’il %s (%s, %s).',
                        $event->actorName,
                        $statusLabel,
                        $this->mealTypeLabel((string) $mealPlan->meal_type),
                        (string) optional($mealPlan->date)->toDateString(),
                    ),
                    data: [
                        'meal_plan_id' => (int) $mealPlan->id,
                        'meal_type' => (string) $mealPlan->meal_type,
                        'date' => (string) optional($mealPlan->date)->toDateString(),
                        'status' => $status,
                        'reason' => $attendance->reason,
                        'actor_user_id' => $event->actorUserId,
                        'actor_name' => $event->actorName,
                    ],
                );
            }
        }

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'calendar',
            type: 'meal_plan.attendance.updated',
            payload: [
                'meal_plan_id' => (int) $mealPlan->id,
                'user_id' => $event->actorUserId,
                'status' => (string) $attendance->status,
                'household_id' => $event->householdId,
            ],
        );
    }
}

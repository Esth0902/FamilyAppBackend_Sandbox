<?php

namespace App\Http\Resources\Calendar;

use App\Models\MealPlan;
use App\Models\MealPlanAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MealPlan */
class MealPlanResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(
        mixed $resource,
        private readonly int $currentUserId = 0,
        private readonly array $householdMembers = [],
        private readonly ?string $message = null,
        private readonly bool $includeResponseWrapper = false,
    ) {
        parent::__construct($resource);
    }

    public static function forBoard(MealPlan $mealPlan, int $currentUserId, array $householdMembers): self
    {
        return new self($mealPlan, $currentUserId, $householdMembers);
    }

    public static function mutation(
        MealPlan $mealPlan,
        int $currentUserId,
        array $householdMembers,
        string $message
    ): self {
        return new self(
            resource: $mealPlan,
            currentUserId: $currentUserId,
            householdMembers: $householdMembers,
            message: $message,
            includeResponseWrapper: true,
        );
    }

    public static function deleted(string $message): self
    {
        return new self(resource: null, message: $message, includeResponseWrapper: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof MealPlan) {
            return [
                'message' => (string) $this->message,
            ];
        }

        $attendances = $this->relationLoaded('attendances')
            ? $this->attendances
            : collect();
        $myAttendance = $attendances->first(
            fn (MealPlanAttendance $attendance): bool => (int) $attendance->user_id === $this->currentUserId
        );

        $payload = [
            'id' => (int) $this->id,
            'date' => optional($this->date)->toDateString(),
            'meal_type' => (string) $this->meal_type,
            'custom_title' => $this->custom_title,
            'note' => $this->note,
            'my_presence' => $myAttendance instanceof MealPlanAttendance
                ? MealPlanAttendanceResource::make($myAttendance)->resolve($request)
                : null,
            'presence_overview' => MealPlanAttendanceResource::overview($attendances, $this->householdMembers),
            'recipes' => $this->items
                ->sortBy('position')
                ->map(static function ($item): array {
                    return [
                        'id' => (int) ($item->recipe?->id ?? 0),
                        'title' => (string) ($item->recipe?->title ?? 'Recette'),
                        'type' => $item->recipe?->type,
                        'servings' => (int) ($item->servings ?? 0),
                        'position' => (int) ($item->position ?? 0),
                    ];
                })
                ->values()
                ->all(),
        ];

        if ($this->includeResponseWrapper) {
            return [
                'message' => (string) $this->message,
                'meal_plan' => $payload,
            ];
        }

        return $payload;
    }
}

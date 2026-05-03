<?php

namespace App\Listeners\Calendar\Concerns;

use App\Models\Event;
use App\Models\Household;
use App\Models\User;

trait InteractsWithCalendarAudience
{
    /**
     * @return array<int, int>
     */
    protected function resolveParentUserIds(int $householdId, ?int $excludeUserId = null): array
    {
        $household = Household::query()->find($householdId);
        if (!$household instanceof Household) {
            return [];
        }

        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0 && ($excludeUserId === null || $id !== $excludeUserId))
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function resolveHouseholdMemberIds(int $householdId, ?int $excludeUserId = null): array
    {
        $household = Household::query()->find($householdId);
        if (!$household instanceof Household) {
            return [];
        }

        return $household->users()
            ->pluck('users.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0 && ($excludeUserId === null || $id !== $excludeUserId))
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function resolveEventAudienceUserIds(Event $event, int $householdId): array
    {
        $audienceMode = Event::normalizeAudienceMode((string) $event->audience_mode);
        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            return $this->resolveHouseholdMemberIds($householdId);
        }

        return $event->invitations()
            ->where('household_id', $householdId)
            ->pluck('user_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param array<int, int> $left
     * @param array<int, int> $right
     * @return array<int, int>
     */
    protected function intersectUserIds(array $left, array $right): array
    {
        $rightLookup = array_fill_keys($right, true);

        return array_values(array_filter(
            $left,
            static fn (int $id): bool => isset($rightLookup[$id])
        ));
    }

    protected function mealTypeLabel(string $mealType): string
    {
        return match ($mealType) {
            'matin' => 'matin',
            'midi' => 'midi',
            default => 'soir',
        };
    }
}

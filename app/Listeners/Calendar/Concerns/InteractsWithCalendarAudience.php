<?php

namespace App\Listeners\Calendar\Concerns;

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

    protected function mealTypeLabel(string $mealType): string
    {
        return match ($mealType) {
            'matin' => 'matin',
            'midi' => 'midi',
            default => 'soir',
        };
    }
}

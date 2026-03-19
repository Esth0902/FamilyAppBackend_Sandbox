<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\Normalization;
use Illuminate\Validation\ValidationException;

trait InteractsWithTaskContext
{
    use ResolvesHouseholdContext;

    protected function isTasksModuleEnabled(Household $household): bool
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        return (bool) ($settings?->has_tasks ?? false);
    }

    protected function ensureTasksModuleEnabled(Household $household): void
    {
        if (!$this->isTasksModuleEnabled($household)) {
            abort(403, 'Le module tâches est désactivé pour ce foyer.');
        }
    }

    protected function ensureTemplateBelongsToHousehold(TaskTemplate $template, Household $household): void
    {
        if ((int) $template->household_id !== (int) $household->id) {
            abort(404, 'Template de tâche introuvable.');
        }
    }

    protected function ensureInstanceBelongsToHousehold(TaskInstance $instance, Household $household): void
    {
        $belongs = TaskTemplate::query()
            ->where('id', $instance->task_template_id)
            ->where('household_id', $household->id)
            ->exists();

        if (!$belongs) {
            abort(404, 'Tâche introuvable.');
        }
    }

    protected function ensureUserBelongsToHousehold(int $userId, Household $household): int
    {
        $exists = $household->users()
            ->where('users.id', $userId)
            ->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'user_id' => ['Le membre sélectionné n appartient pas au foyer.'],
            ]);
        }

        return $userId;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    protected function ensureUsersBelongToHousehold(array $userIds, Household $household, string $field): array
    {
        $normalizedIds = Normalization::memberIds($userIds);
        foreach ($normalizedIds as $userId) {
            $exists = $household->users()
                ->where('users.id', $userId)
                ->exists();
            if (!$exists) {
                throw ValidationException::withMessages([
                    $field => ['Le membre sélectionné n appartient pas au foyer.'],
                ]);
            }
        }

        return $normalizedIds;
    }

    protected function isUserAssignedToInstance(TaskInstance $instance, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ((int) $instance->user_id === $userId) {
            return true;
        }

        if ($instance->relationLoaded('assignees')) {
            return $instance->assignees->contains(
                static fn(User $assignee): bool => (int) $assignee->id === $userId
            );
        }

        return $instance->assignees()
            ->where('users.id', $userId)
            ->exists();
    }
}

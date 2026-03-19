<?php

namespace App\Actions\Tasks;

use App\DTOs\Tasks\UpsertTaskTemplateDTO;
use App\Domain\Tasks\Services\TaskRecurrenceService;
use App\Events\Tasks\TaskTemplateCreatedEvent;
use App\Events\Tasks\TaskTemplateUpdatedEvent;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\Normalization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertTaskTemplateAction
{
    public function __construct(private readonly TaskRecurrenceService $taskRecurrenceService)
    {
    }

    public function execute(Household $household, User $actor, UpsertTaskTemplateDTO $payload, ?TaskTemplate $template = null): TaskTemplate
    {
        $isCreate = !$template;
        $validated = $payload->toArray();

        $persistedTemplate = DB::transaction(function () use ($household, $validated, $template): TaskTemplate {
            if (!$template) {
                return $this->createTemplate($household, $validated);
            }

            return $this->updateTemplate($household, $template, $validated);
        });

        if ($isCreate) {
            event(new TaskTemplateCreatedEvent(
                template: $persistedTemplate,
                householdId: (int) $household->id,
                actorUserId: (int) $actor->id,
                actorName: (string) ($actor->name ?? 'Un membre'),
            ));
        } else {
            event(new TaskTemplateUpdatedEvent(
                template: $persistedTemplate,
                householdId: (int) $household->id,
            ));
        }

        return $persistedTemplate;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createTemplate(Household $household, array $validated): TaskTemplate
    {
        $recurrence = (string) $validated['recurrence'];
        [$recurrence, $recurrenceDays] = $this->taskRecurrenceService->normalizeTemplateRecurrenceAndDays(
            $recurrence,
            $validated['recurrence_days'] ?? null,
        );
        $startDate = $this->taskRecurrenceService->resolveTemplateStartDate(
            $recurrence,
            $validated['start_date'] ?? null,
        );
        $endDate = $this->taskRecurrenceService->resolveTemplateEndDate(
            $recurrence,
            $validated['end_date'] ?? null,
            $startDate,
        );

        $isRotation = (bool) ($validated['is_rotation'] ?? false);
        $fixedUserId = $validated['fixed_user_id'] ?? null;
        if ($fixedUserId !== null) {
            $fixedUserId = $this->ensureUserBelongsToHousehold((int) $fixedUserId, $household);
        }
        $assigneeUserIds = Normalization::memberIds($validated['assignee_user_ids'] ?? null);
        $rotationUserIds = Normalization::memberIds($validated['rotation_user_ids'] ?? null);

        if (!$isRotation && count($assigneeUserIds) === 0 && $fixedUserId !== null) {
            $assigneeUserIds = [$fixedUserId];
        }
        if ($isRotation && count($rotationUserIds) === 0 && $fixedUserId !== null) {
            $rotationUserIds = [$fixedUserId];
        }

        if ($isRotation) {
            $rotationUserIds = $this->ensureUsersBelongToHousehold($rotationUserIds, $household, 'rotation_user_ids');
            if (count($rotationUserIds) === 0) {
                throw ValidationException::withMessages([
                    'rotation_user_ids' => ['Sélectionnez les membres de la rotation et leur ordre.'],
                ]);
            }

            $assigneeUserIds = [];
            $fixedUserId = (int) ($rotationUserIds[0] ?? 0) ?: null;
        } else {
            $assigneeUserIds = $this->ensureUsersBelongToHousehold($assigneeUserIds, $household, 'assignee_user_ids');
            if (count($assigneeUserIds) === 0) {
                throw ValidationException::withMessages([
                    'assignee_user_ids' => ['Sélectionnez au moins un membre pour cette routine.'],
                ]);
            }

            $rotationUserIds = [];
            $fixedUserId = (int) ($assigneeUserIds[0] ?? 0) ?: null;
        }

        $isInterHouseholdAlternating = (bool) ($validated['is_inter_household_alternating'] ?? false);
        $alternatingCustody = $this->resolveAlternatingCustodySettings($household);
        $interHouseholdWeekStartDay = $this->taskRecurrenceService->resolveInterHouseholdWeekStartDay($alternatingCustody);
        $interHouseholdWeekStart = $this->taskRecurrenceService->resolveInterHouseholdWeekStart(
            $isInterHouseholdAlternating,
            $validated['inter_household_week_start'] ?? null,
            $interHouseholdWeekStartDay,
        );

        return TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => trim((string) $validated['name']),
            'description' => $validated['description'] ?? null,
            'recurrence' => $recurrence,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'recurrence_days' => count($recurrenceDays) > 0 ? $recurrenceDays : null,
            'assignee_user_ids' => count($assigneeUserIds) > 0 ? $assigneeUserIds : null,
            'rotation_user_ids' => count($rotationUserIds) > 0 ? $rotationUserIds : null,
            'is_rotation' => $isRotation,
            'rotation_cycle_weeks' => $isRotation
                ? max(1, min(2, (int) ($validated['rotation_cycle_weeks'] ?? 1)))
                : 1,
            'is_inter_household_alternating' => $isInterHouseholdAlternating,
            'inter_household_week_start' => $interHouseholdWeekStart,
            'fixed_user_id' => $fixedUserId ? (int) $fixedUserId : null,
        ])->load('fixedUser:id,name');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function updateTemplate(Household $household, TaskTemplate $template, array $validated): TaskTemplate
    {
        if (array_key_exists('fixed_user_id', $validated) && $validated['fixed_user_id'] !== null) {
            $this->ensureUserBelongsToHousehold((int) $validated['fixed_user_id'], $household);
        }

        $updates = [];
        if (array_key_exists('name', $validated)) {
            $updates['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }
        if (array_key_exists('recurrence', $validated)) {
            $updates['recurrence'] = (string) $validated['recurrence'];
        }
        if (array_key_exists('recurrence_days', $validated)) {
            $updates['recurrence_days'] = Normalization::recurrenceDays($validated['recurrence_days'] ?? null);
        }
        if (array_key_exists('start_date', $validated)) {
            $updates['start_date'] = $validated['start_date'];
        }
        if (array_key_exists('end_date', $validated)) {
            $updates['end_date'] = $validated['end_date'];
        }
        if (array_key_exists('assignee_user_ids', $validated)) {
            $updates['assignee_user_ids'] = $this->ensureUsersBelongToHousehold(
                Normalization::memberIds($validated['assignee_user_ids'] ?? null),
                $household,
                'assignee_user_ids',
            );
        }
        if (array_key_exists('rotation_user_ids', $validated)) {
            $updates['rotation_user_ids'] = $this->ensureUsersBelongToHousehold(
                Normalization::memberIds($validated['rotation_user_ids'] ?? null),
                $household,
                'rotation_user_ids',
            );
        }
        if (array_key_exists('is_rotation', $validated)) {
            $updates['is_rotation'] = (bool) $validated['is_rotation'];
        }
        if (array_key_exists('rotation_cycle_weeks', $validated)) {
            $updates['rotation_cycle_weeks'] = max(1, min(2, (int) ($validated['rotation_cycle_weeks'] ?? 1)));
        }
        if (array_key_exists('is_inter_household_alternating', $validated)) {
            $updates['is_inter_household_alternating'] = (bool) $validated['is_inter_household_alternating'];
        }
        if (array_key_exists('inter_household_week_start', $validated)) {
            $updates['inter_household_week_start'] = $validated['inter_household_week_start'];
        }
        if (array_key_exists('fixed_user_id', $validated)) {
            $updates['fixed_user_id'] = $validated['fixed_user_id'] ? (int) $validated['fixed_user_id'] : null;
        }

        $resolvedRecurrenceInput = (string) ($updates['recurrence'] ?? $template->recurrence ?? 'weekly');
        $resolvedRecurrenceDaysInput = array_key_exists('recurrence_days', $updates)
            ? $updates['recurrence_days']
            : $template->recurrence_days;
        [$resolvedRecurrence, $resolvedRecurrenceDays] = $this->taskRecurrenceService->normalizeTemplateRecurrenceAndDays(
            $resolvedRecurrenceInput,
            $resolvedRecurrenceDaysInput,
        );
        $updates['recurrence'] = $resolvedRecurrence;
        $updates['recurrence_days'] = count($resolvedRecurrenceDays) > 0
            ? $resolvedRecurrenceDays
            : null;

        $templateStartDate = optional($template->start_date)->toDateString();
        $templateCreatedAt = $template->created_at;

        $rawStartDate = array_key_exists('start_date', $updates)
            ? $updates['start_date']
            : $this->taskRecurrenceService->resolveTemplateStartDateValue(
                (string) ($template->recurrence ?? 'weekly'),
                $templateStartDate,
                $templateCreatedAt,
            );
        $resolvedStartDate = $this->taskRecurrenceService->resolveTemplateStartDate(
            $resolvedRecurrence,
            $rawStartDate,
            $templateStartDate,
            $templateCreatedAt,
        );
        $updates['start_date'] = $resolvedStartDate;

        $rawEndDate = array_key_exists('end_date', $updates)
            ? $updates['end_date']
            : optional($template->end_date)->toDateString();
        $updates['end_date'] = $this->taskRecurrenceService->resolveTemplateEndDate(
            $resolvedRecurrence,
            $rawEndDate,
            $resolvedStartDate,
        );

        $resolvedIsRotation = (bool) ($updates['is_rotation'] ?? $template->is_rotation);
        if (!$resolvedIsRotation) {
            $updates['rotation_cycle_weeks'] = 1;
        }

        $assigneeUserIds = array_key_exists('assignee_user_ids', $updates)
            ? $updates['assignee_user_ids']
            : Normalization::memberIds($template->assignee_user_ids);
        $rotationUserIds = array_key_exists('rotation_user_ids', $updates)
            ? $updates['rotation_user_ids']
            : Normalization::memberIds($template->rotation_user_ids);
        $fixedUserId = array_key_exists('fixed_user_id', $updates)
            ? $updates['fixed_user_id']
            : ($template->fixed_user_id ? (int) $template->fixed_user_id : null);

        if (!$resolvedIsRotation && count($assigneeUserIds) === 0 && $fixedUserId !== null) {
            $assigneeUserIds = [(int) $fixedUserId];
        }
        if ($resolvedIsRotation && count($rotationUserIds) === 0 && $fixedUserId !== null) {
            $rotationUserIds = [(int) $fixedUserId];
        }

        if ($resolvedIsRotation) {
            $rotationUserIds = $this->ensureUsersBelongToHousehold($rotationUserIds, $household, 'rotation_user_ids');
            if (count($rotationUserIds) === 0) {
                throw ValidationException::withMessages([
                    'rotation_user_ids' => ['Sélectionnez les membres de la rotation et leur ordre.'],
                ]);
            }

            $assigneeUserIds = [];
            $fixedUserId = (int) ($rotationUserIds[0] ?? 0) ?: null;
        } else {
            $assigneeUserIds = $this->ensureUsersBelongToHousehold($assigneeUserIds, $household, 'assignee_user_ids');
            if (count($assigneeUserIds) === 0) {
                throw ValidationException::withMessages([
                    'assignee_user_ids' => ['Sélectionnez au moins un membre pour cette routine.'],
                ]);
            }

            $rotationUserIds = [];
            $fixedUserId = (int) ($assigneeUserIds[0] ?? 0) ?: null;
        }

        $updates['assignee_user_ids'] = count($assigneeUserIds) > 0 ? $assigneeUserIds : null;
        $updates['rotation_user_ids'] = count($rotationUserIds) > 0 ? $rotationUserIds : null;
        $updates['fixed_user_id'] = $fixedUserId;

        if (
            array_key_exists('is_inter_household_alternating', $validated)
            || array_key_exists('inter_household_week_start', $validated)
        ) {
            $alternatingCustody = $this->resolveAlternatingCustodySettings($household);
            $interHouseholdWeekStartDay = $this->taskRecurrenceService->resolveInterHouseholdWeekStartDay($alternatingCustody);
            $isInterHouseholdAlternating = (bool) ($updates['is_inter_household_alternating'] ?? $template->is_inter_household_alternating);
            $rawInterHouseholdWeekStart = array_key_exists('inter_household_week_start', $updates)
                ? $updates['inter_household_week_start']
                : optional($template->inter_household_week_start)->toDateString();
            $updates['inter_household_week_start'] = $this->taskRecurrenceService->resolveInterHouseholdWeekStart(
                $isInterHouseholdAlternating,
                $rawInterHouseholdWeekStart,
                $interHouseholdWeekStartDay,
            );
            $updates['is_inter_household_alternating'] = $isInterHouseholdAlternating;
        }

        if (count($updates) > 0) {
            $template->update($updates);
        }

        return $template->fresh()->load('fixedUser:id,name');
    }

    private function ensureUserBelongsToHousehold(int $userId, Household $household): int
    {
        $exists = $household->users()
            ->where('users.id', $userId)
            ->exists();

        if (!$exists) {
            throw ValidationException::withMessages([
                'user_id' => ['Le membre sélectionné n\'appartient pas au foyer.'],
            ]);
        }

        return $userId;
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    private function ensureUsersBelongToHousehold(array $userIds, Household $household, string $field): array
    {
        $normalizedIds = Normalization::memberIds($userIds);
        foreach ($normalizedIds as $userId) {
            $exists = $household->users()
                ->where('users.id', $userId)
                ->exists();
            if (!$exists) {
                throw ValidationException::withMessages([
                    $field => ['Le membre sélectionné n\'appartient pas au foyer.'],
                ]);
            }
        }

        return $normalizedIds;
    }

    /**
     * @return array{enabled:bool,change_day:int,home_week_start:string|null}
     */
    private function resolveAlternatingCustodySettings(Household $household): array
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();
        $tasksConfig = is_array($settings?->tasks_config) ? $settings->tasks_config : [];

        return $this->taskRecurrenceService->resolveAlternatingCustodySettings($tasksConfig);
    }
}

<?php

namespace App\Actions\Tasks;

use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\TaskTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpsertTaskTemplateAction
{
    private const FULL_WEEK_DAYS = [1, 2, 3, 4, 5, 6, 7];

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Household $household, array $validated, ?TaskTemplate $template = null): TaskTemplate
    {
        return DB::transaction(function () use ($household, $validated, $template): TaskTemplate {
            if (!$template) {
                return $this->createTemplate($household, $validated);
            }

            return $this->updateTemplate($household, $template, $validated);
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createTemplate(Household $household, array $validated): TaskTemplate
    {
        $recurrence = (string) $validated['recurrence'];
        [$recurrence, $recurrenceDays] = $this->normalizeTemplateRecurrenceAndDays(
            $recurrence,
            $validated['recurrence_days'] ?? null
        );
        $startDate = $this->resolveTemplateStartDate(
            $recurrence,
            $validated['start_date'] ?? null,
            null
        );
        $endDate = $this->resolveTemplateEndDate(
            $recurrence,
            $validated['end_date'] ?? null,
            $startDate
        );

        $isRotation = (bool) ($validated['is_rotation'] ?? false);
        $fixedUserId = $validated['fixed_user_id'] ?? null;
        if ($fixedUserId !== null) {
            $fixedUserId = $this->ensureUserBelongsToHousehold((int) $fixedUserId, $household);
        }
        $assigneeUserIds = $this->normalizeMemberIdsInput($validated['assignee_user_ids'] ?? null);
        $rotationUserIds = $this->normalizeMemberIdsInput($validated['rotation_user_ids'] ?? null);

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
        $interHouseholdWeekStartDay = $this->resolveInterHouseholdWeekStartDay($alternatingCustody);
        $interHouseholdWeekStart = $this->resolveInterHouseholdWeekStart(
            $isInterHouseholdAlternating,
            $validated['inter_household_week_start'] ?? null,
            $interHouseholdWeekStartDay
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
        $this->ensureTemplateBelongsToHousehold($template, $household);

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
            $updates['recurrence_days'] = $this->normalizeRecurrenceDaysInput($validated['recurrence_days'] ?? null);
        }
        if (array_key_exists('start_date', $validated)) {
            $updates['start_date'] = $validated['start_date'];
        }
        if (array_key_exists('end_date', $validated)) {
            $updates['end_date'] = $validated['end_date'];
        }
        if (array_key_exists('assignee_user_ids', $validated)) {
            $updates['assignee_user_ids'] = $this->ensureUsersBelongToHousehold(
                $this->normalizeMemberIdsInput($validated['assignee_user_ids'] ?? null),
                $household,
                'assignee_user_ids'
            );
        }
        if (array_key_exists('rotation_user_ids', $validated)) {
            $updates['rotation_user_ids'] = $this->ensureUsersBelongToHousehold(
                $this->normalizeMemberIdsInput($validated['rotation_user_ids'] ?? null),
                $household,
                'rotation_user_ids'
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
        [$resolvedRecurrence, $resolvedRecurrenceDays] = $this->normalizeTemplateRecurrenceAndDays(
            $resolvedRecurrenceInput,
            $resolvedRecurrenceDaysInput
        );
        $updates['recurrence'] = $resolvedRecurrence;
        $updates['recurrence_days'] = count($resolvedRecurrenceDays) > 0
            ? $resolvedRecurrenceDays
            : null;

        $rawStartDate = array_key_exists('start_date', $updates)
            ? $updates['start_date']
            : $this->resolveTemplateStartDateValue($template);
        $resolvedStartDate = $this->resolveTemplateStartDate(
            $resolvedRecurrence,
            $rawStartDate,
            $template
        );
        $updates['start_date'] = $resolvedStartDate;

        $rawEndDate = array_key_exists('end_date', $updates)
            ? $updates['end_date']
            : optional($template->end_date)->toDateString();
        $updates['end_date'] = $this->resolveTemplateEndDate(
            $resolvedRecurrence,
            $rawEndDate,
            $resolvedStartDate
        );

        $resolvedIsRotation = (bool) ($updates['is_rotation'] ?? $template->is_rotation);
        if (!$resolvedIsRotation) {
            $updates['rotation_cycle_weeks'] = 1;
        }

        $assigneeUserIds = array_key_exists('assignee_user_ids', $updates)
            ? $updates['assignee_user_ids']
            : $this->normalizeMemberIdsInput($template->assignee_user_ids);
        $rotationUserIds = array_key_exists('rotation_user_ids', $updates)
            ? $updates['rotation_user_ids']
            : $this->normalizeMemberIdsInput($template->rotation_user_ids);
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
            $interHouseholdWeekStartDay = $this->resolveInterHouseholdWeekStartDay($alternatingCustody);
            $isInterHouseholdAlternating = (bool) ($updates['is_inter_household_alternating'] ?? $template->is_inter_household_alternating);
            $rawInterHouseholdWeekStart = array_key_exists('inter_household_week_start', $updates)
                ? $updates['inter_household_week_start']
                : optional($template->inter_household_week_start)->toDateString();
            $updates['inter_household_week_start'] = $this->resolveInterHouseholdWeekStart(
                $isInterHouseholdAlternating,
                $rawInterHouseholdWeekStart,
                $interHouseholdWeekStartDay
            );
            $updates['is_inter_household_alternating'] = $isInterHouseholdAlternating;
        }

        if (count($updates) > 0) {
            $template->update($updates);
        }

        return $template->fresh()->load('fixedUser:id,name');
    }

    private function ensureTemplateBelongsToHousehold(TaskTemplate $template, Household $household): void
    {
        if ((int) $template->household_id !== (int) $household->id) {
            abort(404, 'Template de tâche introuvable.');
        }
    }

    private function ensureUserBelongsToHousehold(int $userId, Household $household): int
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
    private function ensureUsersBelongToHousehold(array $userIds, Household $household, string $field): array
    {
        $normalizedIds = $this->normalizeMemberIdsInput($userIds);
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

    /**
     * @return array{enabled:bool,change_day:int,home_week_start:string|null}
     */
    private function resolveAlternatingCustodySettings(Household $household): array
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();
        $tasksConfig = is_array($settings?->tasks_config) ? $settings->tasks_config : [];
        $enabled = (bool) ($tasksConfig['alternating_custody_enabled'] ?? false);
        $changeDay = $this->normalizeIsoWeekDay($tasksConfig['custody_change_day'] ?? 5, 5);
        $homeWeekStart = $this->resolveCustodyHomeWeekStart(
            $enabled,
            $tasksConfig['custody_home_week_start'] ?? null,
            $changeDay
        );

        return [
            'enabled' => $enabled,
            'change_day' => $changeDay,
            'home_week_start' => $homeWeekStart,
        ];
    }

    private function resolveInterHouseholdWeekStart(
        bool $isEnabled,
        mixed $rawWeekStart,
        int $weekStartDay
    ): ?string {
        if (!$isEnabled) {
            return null;
        }

        $startDate = is_string($rawWeekStart) && trim($rawWeekStart) !== ''
            ? Carbon::createFromFormat('Y-m-d', trim($rawWeekStart))->startOfDay()
            : now()->startOfDay();
        $normalizedWeekStartDay = $this->normalizeIsoWeekDay($weekStartDay, 1);

        return $this->startOfCustomWeek($startDate, $normalizedWeekStartDay)->toDateString();
    }

    private function resolveInterHouseholdWeekStartDay(array $alternatingCustody): int
    {
        if (!(bool) ($alternatingCustody['enabled'] ?? false)) {
            return 1;
        }

        return $this->normalizeIsoWeekDay($alternatingCustody['change_day'] ?? 1, 1);
    }

    private function normalizeIsoWeekDay(mixed $value, int $default = 1): int
    {
        $parsed = (int) $value;
        if ($parsed < 1 || $parsed > 7) {
            return $default;
        }

        return $parsed;
    }

    private function resolveCustodyHomeWeekStart(bool $isEnabled, mixed $rawDate, int $changeDay): ?string
    {
        if (!$isEnabled) {
            return null;
        }

        $baseDate = is_string($rawDate) && trim($rawDate) !== ''
            ? Carbon::createFromFormat('Y-m-d', trim($rawDate))->startOfDay()
            : now()->startOfDay();
        $startOfWeek = $this->startOfCustomWeek($baseDate, $changeDay);

        return $startOfWeek->toDateString();
    }

    private function startOfCustomWeek(Carbon $date, int $startDayIso): Carbon
    {
        $normalized = $date->copy()->startOfDay();
        $delta = ((int) $normalized->dayOfWeekIso - $startDayIso + 7) % 7;

        return $normalized->subDays($delta);
    }

    private function resolveTemplateStartDate(string $recurrence, mixed $rawStartDate, ?TaskTemplate $template): ?string
    {
        if ($recurrence === 'once') {
            return null;
        }

        if (is_string($rawStartDate) && trim($rawStartDate) !== '') {
            return Carbon::createFromFormat('Y-m-d', trim($rawStartDate))->startOfDay()->toDateString();
        }

        if ($template?->start_date) {
            return Carbon::parse($template->start_date)->startOfDay()->toDateString();
        }

        if ($template?->created_at) {
            return Carbon::parse($template->created_at)->startOfDay()->toDateString();
        }

        return now()->startOfDay()->toDateString();
    }

    private function resolveTemplateEndDate(string $recurrence, mixed $rawEndDate, ?string $startDate): ?string
    {
        if ($recurrence === 'once') {
            return null;
        }

        if (!is_string($rawEndDate) || trim($rawEndDate) === '') {
            return null;
        }

        $resolvedEndDate = Carbon::createFromFormat('Y-m-d', trim($rawEndDate))->startOfDay()->toDateString();
        if (is_string($startDate) && trim($startDate) !== '' && $resolvedEndDate < $startDate) {
            throw ValidationException::withMessages([
                'end_date' => ['La date de fin doit être égale ou postérieure à la date de début de la routine.'],
            ]);
        }

        return $resolvedEndDate;
    }

    private function resolveTemplateStartDateValue(?TaskTemplate $template): ?string
    {
        if (!$template) {
            return null;
        }

        if ($template->start_date) {
            return Carbon::parse($template->start_date)->startOfDay()->toDateString();
        }

        if ((string) $template->recurrence !== 'once' && $template->created_at) {
            return Carbon::parse($template->created_at)->startOfDay()->toDateString();
        }

        return null;
    }

    /**
     * @param  array<int, int>|mixed  $value
     * @return array<int, int>
     */
    private function normalizeMemberIdsInput(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $candidate) {
            $id = (int) $candidate;
            if ($id <= 0) {
                continue;
            }
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  array<int, int>|mixed  $value
     * @return array<int, int>
     */
    private function normalizeRecurrenceDaysInput(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $days = [];
        foreach ($value as $dayValue) {
            $day = (int) $dayValue;
            if ($day < 1 || $day > 7) {
                continue;
            }
            if (!in_array($day, $days, true)) {
                $days[] = $day;
            }
        }

        sort($days);

        return $days;
    }

    /**
     * @return array{0:string,1:array<int,int>}
     */
    private function normalizeTemplateRecurrenceAndDays(string $recurrence, mixed $rawRecurrenceDays): array
    {
        $normalizedRecurrence = in_array($recurrence, ['daily', 'weekly', 'monthly', 'once'], true)
            ? $recurrence
            : 'weekly';
        $days = $this->normalizeRecurrenceDaysInput($rawRecurrenceDays);

        if (!in_array($normalizedRecurrence, ['daily', 'weekly'], true)) {
            return [$normalizedRecurrence, []];
        }

        if ($normalizedRecurrence === 'daily') {
            if (count($days) === 0 || count($days) === 7) {
                return ['daily', self::FULL_WEEK_DAYS];
            }

            return ['weekly', $days];
        }

        if (count($days) === 7) {
            return ['daily', self::FULL_WEEK_DAYS];
        }

        return ['weekly', $days];
    }
}

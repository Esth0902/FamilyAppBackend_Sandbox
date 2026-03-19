<?php

namespace App\Domain\Tasks\Services;

use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Normalization;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class TaskRecurrenceService
{
    private const STATUS_TODO = "\u{00E0} faire";

    public function resolveInterHouseholdWeekStartDay(array $alternatingCustody): int
    {
        if (!(bool) ($alternatingCustody['enabled'] ?? false)) {
            return 1;
        }

        return Normalization::isoWeekDay($alternatingCustody['change_day'] ?? 1, 1);
    }

    public function resolveCustodyHomeWeekStart(bool $isEnabled, mixed $rawDate, int $changeDay): ?string
    {
        if (!$isEnabled) {
            return null;
        }

        $baseDate = is_string($rawDate) && trim($rawDate) !== ''
            ? Carbon::createFromFormat('Y-m-d', trim($rawDate))->startOfDay()
            : now()->startOfDay();

        return $this->startOfCustomWeek($baseDate, $changeDay)->toDateString();
    }

    /**
     * @param  Collection<int, TaskTemplate>  $templates
     * @param  Collection<int, array{id:int,name:string,role:string}>  $members
     */
    public function ensureRecurringInstances(
        Collection $templates,
        Collection $members,
        Carbon $fromDate,
        Carbon $toDate,
        array $alternatingCustody,
        int $householdId,
        int $interHouseholdWeekStartDay
    ): void {
        if ($members->isEmpty() || $templates->isEmpty()) {
            return;
        }

        $templateIds = $templates
            ->map(static fn (TaskTemplate $template): int => (int) $template->id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if (count($templateIds) === 0) {
            return;
        }

        $periodDays = collect(CarbonPeriod::create($fromDate->copy(), '1 day', $toDate->copy()))
            ->map(static fn (Carbon $day): Carbon => $day->copy()->startOfDay())
            ->values();

        if ($periodDays->isEmpty()) {
            return;
        }

        $existingInstances = TaskInstance::query()
            ->whereIn('task_template_id', $templateIds)
            ->whereBetween('due_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->orderBy('task_template_id')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get([
                'id',
                'task_template_id',
                'user_id',
                'due_date',
                'status',
                'validated_by_parent',
            ]);

        $instancesByTemplateDate = [];
        $existingInstanceIds = [];

        foreach ($existingInstances as $existingInstance) {
            $existingDate = optional($existingInstance->due_date)->toDateString();
            if (!is_string($existingDate) || $existingDate === '') {
                continue;
            }

            $instanceKey = $this->buildTemplateDateKey((int) $existingInstance->task_template_id, $existingDate);
            if (!array_key_exists($instanceKey, $instancesByTemplateDate)) {
                $instancesByTemplateDate[$instanceKey] = $existingInstance;
            }

            $existingInstanceIds[] = (int) $existingInstance->id;
        }

        $acceptedReassignmentCache = $this->buildAcceptedReassignmentCache($existingInstanceIds, $householdId);

        foreach ($templates as $template) {
            if ((string) $template->recurrence === 'once') {
                continue;
            }

            foreach ($periodDays as $day) {
                $date = $day->copy()->startOfDay();
                if (!$this->templateAppliesToDate($template, $date, $interHouseholdWeekStartDay)) {
                    continue;
                }

                $assigneeIds = $this->resolveAssigneeIds($template, $members, $date);
                if (count($assigneeIds) === 0) {
                    continue;
                }

                $filteredAssigneeIds = collect($assigneeIds)
                    ->filter(function (int $assigneeId) use ($alternatingCustody, $members, $date): bool {
                        if (!$this->isAlternatingCustodyEnabledForChildAssignee($alternatingCustody, $members, $assigneeId)) {
                            return true;
                        }

                        return $this->isDateInAlternatingCustodyHomeWeek($date, $alternatingCustody);
                    })
                    ->values()
                    ->all();

                $primaryAssigneeId = $this->resolvePrimaryAssigneeId($filteredAssigneeIds);
                if ($primaryAssigneeId <= 0) {
                    continue;
                }

                $dateString = $date->toDateString();
                $instanceKey = $this->buildTemplateDateKey((int) $template->id, $dateString);
                /** @var TaskInstance|null $existing */
                $existing = $instancesByTemplateDate[$instanceKey] ?? null;

                if ($existing) {
                    if ($this->instanceHasAcceptedReassignment((int) $existing->id, $acceptedReassignmentCache)) {
                        continue;
                    }

                    if (
                        (int) $existing->user_id !== $primaryAssigneeId
                        && (string) $existing->status === self::STATUS_TODO
                        && !$existing->validated_by_parent
                    ) {
                        $existing->update(['user_id' => $primaryAssigneeId]);
                    }

                    if ((string) $existing->status === self::STATUS_TODO && !$existing->validated_by_parent) {
                        $this->syncInstanceAssignees($existing, $filteredAssigneeIds);
                    }

                    continue;
                }

                $created = TaskInstance::query()->create([
                    'task_template_id' => (int) $template->id,
                    'user_id' => $primaryAssigneeId,
                    'due_date' => $dateString,
                    'status' => self::STATUS_TODO,
                    'validated_by_parent' => false,
                ]);
                $this->syncInstanceAssignees($created, $filteredAssigneeIds);
                $instancesByTemplateDate[$instanceKey] = $created;
                $acceptedReassignmentCache[(int) $created->id] = false;
            }
        }
    }

    /**
     * @param  Collection<int, array{id:int,name:string,role:string}>  $members
     */
    private function isAlternatingCustodyEnabledForChildAssignee(
        array $alternatingCustody,
        Collection $members,
        int $assigneeId
    ): bool {
        if (!(bool) ($alternatingCustody['enabled'] ?? false)) {
            return false;
        }

        $member = $members->first(
            static fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === $assigneeId
        );

        return is_array($member) && (string) ($member['role'] ?? '') === User::ROLE_CHILD;
    }

    private function isDateInAlternatingCustodyHomeWeek(Carbon $date, array $alternatingCustody): bool
    {
        if (!(bool) ($alternatingCustody['enabled'] ?? false)) {
            return true;
        }

        $changeDay = Normalization::isoWeekDay($alternatingCustody['change_day'] ?? 5, 5);
        $homeWeekStartRaw = (string) ($alternatingCustody['home_week_start'] ?? '');
        if ($homeWeekStartRaw === '') {
            return true;
        }

        $homeWeekStart = $this->startOfCustomWeek(
            Carbon::createFromFormat('Y-m-d', $homeWeekStartRaw)->startOfDay(),
            $changeDay
        );
        $targetWeekStart = $this->startOfCustomWeek($date->copy()->startOfDay(), $changeDay);
        $weeksFromHome = (int) $homeWeekStart->diffInWeeks($targetWeekStart, false);

        return abs($weeksFromHome) % 2 === 0;
    }

    private function buildTemplateDateKey(int $templateId, string $date): string
    {
        return $templateId . '|' . $date;
    }

    /**
     * @param  array<int, int>  $instanceIds
     * @return array<int, bool>
     */
    private function buildAcceptedReassignmentCache(array $instanceIds, int $householdId): array
    {
        $normalizedInstanceIds = collect($instanceIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (count($normalizedInstanceIds) === 0 || $householdId <= 0) {
            return [];
        }

        $cache = [];
        foreach ($normalizedInstanceIds as $instanceId) {
            $cache[$instanceId] = false;
        }

        $notifications = UserNotification::query()
            ->where('household_id', $householdId)
            ->where('type', 'task_reassignment_invite')
            ->where('data->status', 'accepted')
            ->get(['data']);

        foreach ($notifications as $notification) {
            $notificationData = is_array($notification->data) ? $notification->data : [];
            $instanceId = (int) ($notificationData['task_instance_id'] ?? 0);
            if ($instanceId > 0 && array_key_exists($instanceId, $cache)) {
                $cache[$instanceId] = true;
            }
        }

        return $cache;
    }

    /**
     * @param  array<int, bool>  $cache
     */
    private function instanceHasAcceptedReassignment(int $instanceId, array &$cache): bool
    {
        if ($instanceId <= 0) {
            return false;
        }

        if (array_key_exists($instanceId, $cache)) {
            return (bool) $cache[$instanceId];
        }

        $cache[$instanceId] = UserNotification::query()
            ->where('type', 'task_reassignment_invite')
            ->where('data->task_instance_id', $instanceId)
            ->where('data->status', 'accepted')
            ->exists();

        return (bool) $cache[$instanceId];
    }

    private function templateAppliesToDate(
        TaskTemplate $template,
        Carbon $date,
        int $interHouseholdWeekStartDay
    ): bool {
        $anchor = $this->resolveTemplateAnchorDate($template, $date);
        $startDate = $template->start_date
            ? Carbon::parse($template->start_date)->startOfDay()
            : null;
        $endDate = $template->end_date
            ? Carbon::parse($template->end_date)->startOfDay()
            : null;
        $recurrence = (string) ($template->recurrence ?? 'daily');
        $recurrenceDays = Normalization::recurrenceDays($template->recurrence_days);

        if ($startDate !== null && $date->lt($startDate)) {
            return false;
        }

        if ($endDate !== null && $date->gt($endDate)) {
            return false;
        }

        if (!$this->isDateInInterHouseholdAlternationWeek($template, $date, $anchor, $interHouseholdWeekStartDay)) {
            return false;
        }

        if ($recurrence === 'daily') {
            if (count($recurrenceDays) === 0) {
                return true;
            }

            return in_array((int) $date->dayOfWeekIso, $recurrenceDays, true);
        }

        if ($recurrence === 'weekly') {
            if (count($recurrenceDays) > 0) {
                return in_array((int) $date->dayOfWeekIso, $recurrenceDays, true);
            }

            return $date->dayOfWeekIso === $anchor->dayOfWeekIso;
        }

        if ($recurrence === 'monthly') {
            $targetDay = min($anchor->day, $date->copy()->endOfMonth()->day);

            return $date->day === $targetDay;
        }

        return false;
    }

    private function isDateInInterHouseholdAlternationWeek(
        TaskTemplate $template,
        Carbon $date,
        Carbon $anchor,
        int $interHouseholdWeekStartDay
    ): bool {
        if (!(bool) ($template->is_inter_household_alternating ?? false)) {
            return true;
        }

        $weekStartDay = Normalization::isoWeekDay($interHouseholdWeekStartDay, 1);
        $alternationStartBase = $template->inter_household_week_start
            ? Carbon::parse($template->inter_household_week_start)->startOfDay()
            : $anchor->copy()->startOfDay();
        $alternationStart = $this->startOfCustomWeek($alternationStartBase, $weekStartDay);
        $targetWeekStart = $this->startOfCustomWeek($date->copy()->startOfDay(), $weekStartDay);
        $weeksFromStart = (int) $alternationStart->diffInWeeks($targetWeekStart, false);

        return abs($weeksFromStart) % 2 === 0;
    }

    /**
     * @param  Collection<int, array{id:int,name:string,role:string}>  $members
     * @return array<int, int>
     */
    private function resolveAssigneeIds(TaskTemplate $template, Collection $members, Carbon $date): array
    {
        $memberIds = $members
            ->map(static fn (array $member): int => (int) ($member['id'] ?? 0))
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ((bool) $template->is_rotation) {
            $rotationUserIds = collect(Normalization::memberIds($template->rotation_user_ids))
                ->filter(static fn (int $id): bool => in_array($id, $memberIds, true))
                ->values();

            if ($rotationUserIds->isEmpty() && $template->fixed_user_id) {
                $fallbackId = (int) $template->fixed_user_id;
                if ($fallbackId > 0 && in_array($fallbackId, $memberIds, true)) {
                    $rotationUserIds = collect([$fallbackId]);
                }
            }

            if ($rotationUserIds->isEmpty()) {
                return [];
            }

            $anchorWeek = $this->resolveTemplateAnchorDate($template, $date)->startOfWeek(Carbon::MONDAY);
            $targetWeek = $date->copy()->startOfWeek(Carbon::MONDAY);
            $weeksFromAnchor = max(0, (int) $anchorWeek->diffInWeeks($targetWeek));
            $cycleWeeks = max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1)));
            $rotationOffset = (int) floor($weeksFromAnchor / $cycleWeeks);

            $assigneeIndex = $rotationOffset % $rotationUserIds->count();

            return [(int) ($rotationUserIds->get($assigneeIndex) ?? 0)];
        }

        $assigneeIds = collect(Normalization::memberIds($template->assignee_user_ids))
            ->filter(static fn (int $id): bool => in_array($id, $memberIds, true))
            ->values();

        if ($assigneeIds->isEmpty() && $template->fixed_user_id) {
            $fallbackId = (int) $template->fixed_user_id;
            if ($fallbackId > 0 && in_array($fallbackId, $memberIds, true)) {
                $assigneeIds = collect([$fallbackId]);
            }
        }

        return $assigneeIds->all();
    }

    /**
     * @param  array<int, int>  $assigneeIds
     */
    private function resolvePrimaryAssigneeId(array $assigneeIds): int
    {
        return (int) (Normalization::memberIds($assigneeIds)[0] ?? 0);
    }

    private function resolveTemplateAnchorDate(TaskTemplate $template, Carbon $fallbackDate): Carbon
    {
        if ($template->start_date) {
            return Carbon::parse($template->start_date)->startOfDay();
        }

        if ($template->created_at) {
            return Carbon::parse($template->created_at)->startOfDay();
        }

        return $fallbackDate->copy()->startOfDay();
    }

    private function startOfCustomWeek(Carbon $date, int $startDayIso): Carbon
    {
        $normalized = $date->copy()->startOfDay();
        $delta = ((int) $normalized->dayOfWeekIso - $startDayIso + 7) % 7;

        return $normalized->subDays($delta);
    }

    /**
     * @param  array<int, int>  $assigneeIds
     */
    private function syncInstanceAssignees(TaskInstance $instance, array $assigneeIds): void
    {
        $normalized = Normalization::memberIds($assigneeIds);
        if (count($normalized) === 0) {
            $fallbackUserId = (int) $instance->user_id;
            if ($fallbackUserId > 0) {
                $normalized = [$fallbackUserId];
            }
        }

        if (count($normalized) === 0) {
            return;
        }

        $instance->assignees()->sync($normalized);

        $primaryAssigneeId = $this->resolvePrimaryAssigneeId($normalized);
        if ($primaryAssigneeId > 0 && (int) $instance->user_id !== $primaryAssigneeId) {
            $instance->update(['user_id' => $primaryAssigneeId]);
        }

        $instance->unsetRelation('assignees');
    }
}

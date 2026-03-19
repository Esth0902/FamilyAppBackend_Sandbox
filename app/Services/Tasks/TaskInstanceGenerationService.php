<?php

namespace App\Services\Tasks;

use App\Domain\Tasks\Services\TaskRecurrenceService;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\UserNotification;
use App\Support\Normalization;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

class TaskInstanceGenerationService
{
    private const STATUS_TODO = "\u{00E0} faire";

    public function __construct(private readonly TaskRecurrenceService $taskRecurrenceService)
    {
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
        int $interHouseholdWeekStartDay,
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
                if (!$this->taskRecurrenceService->templateAppliesToDate($template, $date, $interHouseholdWeekStartDay)) {
                    continue;
                }

                $assigneeIds = $this->taskRecurrenceService->resolveAssigneeIds($template, $members, $date);
                if (count($assigneeIds) === 0) {
                    continue;
                }

                $filteredAssigneeIds = collect($assigneeIds)
                    ->filter(function (int $assigneeId) use ($alternatingCustody, $members, $date): bool {
                        if (!$this->taskRecurrenceService->isAlternatingCustodyEnabledForChildAssignee($alternatingCustody, $members, $assigneeId)) {
                            return true;
                        }

                        return $this->taskRecurrenceService->isDateInAlternatingCustodyHomeWeek($date, $alternatingCustody);
                    })
                    ->values()
                    ->all();

                $primaryAssigneeId = $this->taskRecurrenceService->resolvePrimaryAssigneeId($filteredAssigneeIds);
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

        $primaryAssigneeId = $this->taskRecurrenceService->resolvePrimaryAssigneeId($normalized);
        if ($primaryAssigneeId > 0 && (int) $instance->user_id !== $primaryAssigneeId) {
            $instance->update(['user_id' => $primaryAssigneeId]);
        }

        $instance->unsetRelation('assignees');
    }
}
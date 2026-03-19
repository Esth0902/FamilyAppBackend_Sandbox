<?php

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskInstanceCreatedEvent;
use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Resources\Tasks\TaskTemplateResource;
use App\Models\Household;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Support\Normalization;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CreateTaskInstanceAction
{
    use InteractsWithTaskContext;

    private const STATUS_TODO = "\u{00E0} faire";
    private const STATUS_DONE = "r\u{00E9}alis\u{00E9}e";
    private const STATUS_CANCELLED = "annul\u{00E9}e";

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(Household $household, string $role, User $currentUser, array $validated): TaskInstance
    {
        $this->ensureTasksModuleEnabled($household);

        $isParent = $role === User::ROLE_PARENT;
        $currentUserId = (int) $currentUser->id;

        $template = null;
        if (!empty($validated['task_template_id'])) {
            $template = TaskTemplate::query()->findOrFail((int) $validated['task_template_id']);
            $this->ensureTemplateBelongsToHousehold($template, $household);
        }

        if (!$template) {
            $title = trim((string) ($validated['name'] ?? ''));
            if ($title === '') {
                throw ValidationException::withMessages([
                    'name' => ['Le nom de la tâche est obligatoire si aucun template n est fourni.'],
                ]);
            }

            $template = TaskTemplate::query()->create([
                'household_id' => $household->id,
                'name' => $title,
                'description' => $validated['description'] ?? null,
                'recurrence' => 'once',
                'start_date' => null,
                'end_date' => null,
                'recurrence_days' => null,
                'assignee_user_ids' => null,
                'rotation_user_ids' => null,
                'is_rotation' => false,
                'rotation_cycle_weeks' => 1,
                'is_inter_household_alternating' => false,
                'inter_household_week_start' => null,
                'fixed_user_id' => null,
            ]);
        }

        $members = $this->resolveHouseholdMembers($household);
        $dueDate = Carbon::createFromFormat('Y-m-d', (string) $validated['due_date'])->startOfDay();
        $endDate = array_key_exists('end_date', $validated) && is_string($validated['end_date']) && trim($validated['end_date']) !== ''
            ? Carbon::createFromFormat('Y-m-d', (string) $validated['end_date'])->startOfDay()
            : $dueDate->copy();

        if ($dueDate->greaterThan($endDate)) {
            throw ValidationException::withMessages([
                'end_date' => ['La date de fin doit être égale ou postérieure à la date de début.'],
            ]);
        }

        if ((string) $template->recurrence !== 'once' && $dueDate->notEqualTo($endDate)) {
            throw ValidationException::withMessages([
                'end_date' => ['La date de fin est uniquement disponible pour les tâches ponctuelles.'],
            ]);
        }

        $assigneeIds = [];
        if ($isParent) {
            $requestedIds = Normalization::memberIds($validated['user_ids'] ?? null);
            if (!empty($validated['user_id'])) {
                $requestedIds[] = (int) $validated['user_id'];
            }
            $requestedIds = Normalization::memberIds($requestedIds);

            if (count($requestedIds) > 0) {
                $assigneeIds = $this->ensureUsersBelongToHousehold($requestedIds, $household, 'user_ids');
            } else {
                $assigneeIds = $this->resolveAssigneeIds($template, $members, $dueDate);
            }
        } else {
            $this->ensureUserBelongsToHousehold($currentUserId, $household);
            $assigneeIds = [$currentUserId];
        }

        $primaryAssigneeId = $this->resolvePrimaryAssigneeId($assigneeIds);
        if ($primaryAssigneeId <= 0) {
            throw ValidationException::withMessages([
                'user_ids' => ['Impossible de déterminer un membre à assigner.'],
            ]);
        }

        $status = (string) ($validated['status'] ?? self::STATUS_TODO);
        $instances = [];
        $period = CarbonPeriod::create($dueDate->copy(), '1 day', $endDate->copy());

        foreach ($period as $periodDay) {
            $targetDate = $periodDay->copy()->startOfDay();
            $instance = TaskInstance::query()
                ->where('task_template_id', (int) $template->id)
                ->whereDate('due_date', $targetDate->toDateString())
                ->orderBy('id')
                ->first();

            if ($instance) {
                if (
                    (int) $instance->user_id !== $primaryAssigneeId
                    && (string) $instance->status === self::STATUS_TODO
                    && !$instance->validated_by_parent
                ) {
                    $instance->update(['user_id' => $primaryAssigneeId]);
                }

                if ((string) $instance->status === self::STATUS_TODO && !$instance->validated_by_parent) {
                    $this->syncInstanceAssignees($instance, $assigneeIds);
                }
            } else {
                $instance = TaskInstance::query()->create([
                    'task_template_id' => (int) $template->id,
                    'user_id' => $primaryAssigneeId,
                    'due_date' => $targetDate->toDateString(),
                    'status' => $status,
                    'completed_at' => $status === self::STATUS_DONE ? now() : null,
                    'validated_by_parent' => false,
                ]);
                $this->syncInstanceAssignees($instance, $assigneeIds);
            }

            $instances[] = $instance;
        }

        $instance = $instances[0] ?? null;
        if (!$instance) {
            throw ValidationException::withMessages([
                'due_date' => ['Impossible de créer la tâche.'],
            ]);
        }

        $instance->load([
            'template:id,household_id,name,description,recurrence,start_date,end_date,recurrence_days,assignee_user_ids,rotation_user_ids,is_rotation,rotation_cycle_weeks,is_inter_household_alternating,inter_household_week_start,fixed_user_id',
            'user:id,name',
            'assignees:id,name',
        ]);

        event(new TaskInstanceCreatedEvent(
            instance: $instance,
            householdId: (int) $household->id,
            actorUserId: $currentUserId,
            actorName: (string) ($currentUser->name ?? 'Un membre'),
            assigneeIds: $assigneeIds,
            instanceIds: array_values(array_map(static fn(TaskInstance $item): int => (int) $item->id, $instances)),
        ));

        return $instance;
    }

    private function resolveHouseholdMembers(Household $household): Collection
    {
        return $household->users()
            ->select('users.id', 'users.name')
            ->orderBy('users.id')
            ->get()
            ->map(static function (User $user): array {
                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'role' => (string) ($user->pivot->role ?? User::ROLE_CHILD),
                ];
            })
            ->values();
    }

    /**
     * @return array<int, int>
     */
    private function resolveAssigneeIds(TaskTemplate $template, Collection $members, Carbon $date): array
    {
        $memberIds = $members
            ->map(static fn(array $member): int => (int) ($member['id'] ?? 0))
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();

        if ((bool) $template->is_rotation) {
            $rotationUserIds = collect(Normalization::memberIds($template->rotation_user_ids))
                ->filter(static fn(int $id): bool => in_array($id, $memberIds, true))
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
            ->filter(static fn(int $id): bool => in_array($id, $memberIds, true))
            ->values();

        if ($assigneeIds->isEmpty() && $template->fixed_user_id) {
            $fallbackId = (int) $template->fixed_user_id;
            if ($fallbackId > 0 && in_array($fallbackId, $memberIds, true)) {
                $assigneeIds = collect([$fallbackId]);
            }
        }

        return $assigneeIds->all();
    }

    private function resolveTemplateAnchorDate(TaskTemplate $template, Carbon $fallbackDate): Carbon
    {
        $startDate = TaskTemplateResource::resolveStartDateValue($template);
        if (is_string($startDate) && trim($startDate) !== '') {
            return Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
        }

        if ($template->created_at) {
            return Carbon::parse($template->created_at)->startOfDay();
        }

        return $fallbackDate->copy()->startOfDay();
    }

    private function resolvePrimaryAssigneeId(array $assigneeIds): int
    {
        return (int) (Normalization::memberIds($assigneeIds)[0] ?? 0);
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

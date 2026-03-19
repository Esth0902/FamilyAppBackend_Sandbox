<?php

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\ToggleTaskStatusAction;
use App\Actions\Tasks\UpsertTaskTemplateAction;
use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationService;
use App\Services\RealtimePublisher;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Support\Normalization;

class TaskController extends Controller
{
    use ResolvesHouseholdContext;

    private const STATUS_TODO = "\u{00E0} faire";
    private const STATUS_DONE = "r\u{00E9}alis\u{00E9}e";
    private const STATUS_CANCELLED = "annul\u{00E9}e";
    private const DEFAULT_RANGE_DAYS = 14;
    private const MAX_RANGE_DAYS = 45;
    private const FULL_WEEK_DAYS = [1, 2, 3, 4, 5, 6, 7];

    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
        private readonly NotificationService $notificationService,
        private readonly UpsertTaskTemplateAction $upsertTaskTemplateAction,
        private readonly ToggleTaskStatusAction $toggleTaskStatusAction,
    ) {
    }

    public function board(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        [$fromDate, $toDate] = Normalization::dateRange($request, self::DEFAULT_RANGE_DAYS, self::MAX_RANGE_DAYS);

        $tasksEnabled = $this->isTasksModuleEnabled($household);
        $members = $this->resolveHouseholdMembers($household);
        $alternatingCustody = $this->resolveAlternatingCustodySettings($household);
        $interHouseholdWeekStartDay = $this->resolveInterHouseholdWeekStartDay($alternatingCustody);

        $templates = TaskTemplate::query()
            ->where('household_id', $household->id)
            ->with('fixedUser:id,name')
            ->orderBy('id')
            ->get();

        if ($tasksEnabled) {
            $this->ensureRecurringInstances(
                $templates,
                $members,
                $fromDate,
                $toDate,
                $alternatingCustody,
                (int) $household->id,
                $interHouseholdWeekStartDay
            );
        }

        $instances = TaskInstance::query()
            ->whereHas('template', fn($query) => $query->where('household_id', $household->id))
            ->whereBetween('due_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->with([
                'template:id,household_id,name,description,recurrence,start_date,end_date,recurrence_days,assignee_user_ids,rotation_user_ids,is_rotation,rotation_cycle_weeks,is_inter_household_alternating,inter_household_week_start,fixed_user_id',
                'user:id,name',
                'assignees:id,name',
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $currentUserId = (int) $request->user()->id;

        return response()->json([
            'tasks_enabled' => $tasksEnabled,
            'range' => [
                'from' => $fromDate->toDateString(),
                'to' => $toDate->toDateString(),
            ],
            'settings' => [
                'alternating_custody_enabled' => (bool) $alternatingCustody['enabled'],
                'custody_change_day' => (int) $alternatingCustody['change_day'],
                'custody_home_week_start' => $alternatingCustody['home_week_start'],
            ],
            'can_manage_templates' => $role === User::ROLE_PARENT,
            'can_manage_instances' => $tasksEnabled,
            'current_user' => [
                'id' => $currentUserId,
                'role' => $role,
            ],
            'members' => $members->map(static fn(array $member): array => [
                'id' => (int) $member['id'],
                'name' => (string) $member['name'],
                'role' => (string) $member['role'],
            ])->values(),
            'templates' => $templates->map(function (TaskTemplate $template): array {
                return [
                    'id' => (int) $template->id,
                    'name' => (string) $template->name,
                    'description' => $template->description,
                    'recurrence' => (string) $template->recurrence,
                    'start_date' => $this->resolveTemplateStartDateValue($template),
                    'end_date' => optional($template->end_date)->toDateString(),
                    'recurrence_days' => $this->normalizeRecurrenceDaysInput($template->recurrence_days),
                    'assignee_user_ids' => Normalization::memberIds($template->assignee_user_ids),
                    'rotation_user_ids' => Normalization::memberIds($template->rotation_user_ids),
                    'is_rotation' => (bool) $template->is_rotation,
                    'rotation_cycle_weeks' => max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1))),
                    'is_inter_household_alternating' => (bool) ($template->is_inter_household_alternating ?? false),
                    'inter_household_week_start' => optional($template->inter_household_week_start)->toDateString(),
                    'fixed_user_id' => $template->fixed_user_id ? (int) $template->fixed_user_id : null,
                    'fixed_user_name' => $template->fixedUser?->name,
                ];
            })->values(),
            'instances' => $instances->map(function (TaskInstance $instance) use ($currentUserId, $role): array {
                $isParent = $role === User::ROLE_PARENT;
                $isAssignedUser = $this->isUserAssignedToInstance($instance, $currentUserId);
                $status = (string) $instance->status;
                $assignees = $instance->assignees
                    ->sortBy('id')
                    ->map(static fn(User $user): array => [
                        'id' => (int) $user->id,
                        'name' => (string) $user->name,
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => (int) $instance->id,
                    'task_template_id' => (int) $instance->task_template_id,
                    'title' => (string) ($instance->template?->name ?? 'Tâche'),
                    'description' => $instance->template?->description,
                    'due_date' => optional($instance->due_date)->toDateString(),
                    'status' => $status,
                    'completed_at' => optional($instance->completed_at)->toIso8601String(),
                    'validated_by_parent' => (bool) $instance->validated_by_parent,
                    'assignee' => [
                        'id' => (int) ($instance->user?->id ?? 0),
                        'name' => (string) ($instance->user?->name ?? 'Membre'),
                    ],
                    'assignees' => $assignees,
                    'template' => [
                        'id' => (int) ($instance->template?->id ?? 0),
                        'recurrence' => (string) ($instance->template?->recurrence ?? 'once'),
                        'start_date' => $this->resolveTemplateStartDateValue($instance->template),
                        'end_date' => optional($instance->template?->end_date)->toDateString(),
                        'recurrence_days' => $this->normalizeRecurrenceDaysInput($instance->template?->recurrence_days),
                        'assignee_user_ids' => Normalization::memberIds($instance->template?->assignee_user_ids),
                        'rotation_user_ids' => Normalization::memberIds($instance->template?->rotation_user_ids),
                        'is_rotation' => (bool) ($instance->template?->is_rotation ?? false),
                        'rotation_cycle_weeks' => max(1, min(2, (int) ($instance->template?->rotation_cycle_weeks ?? 1))),
                        'is_inter_household_alternating' => (bool) ($instance->template?->is_inter_household_alternating ?? false),
                        'inter_household_week_start' => optional($instance->template?->inter_household_week_start)->toDateString(),
                    ],
                    'permissions' => [
                        'can_toggle' => $isParent || $isAssignedUser,
                        'can_validate' => $isParent && $status === self::STATUS_DONE,
                        'can_cancel' => $isParent,
                    ],
                ];
            })->values(),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureTasksModuleEnabled($household);
        $this->ensureParentRole($role);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'recurrence' => ['required', 'in:daily,weekly,monthly,once'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'recurrence_days' => ['nullable', 'array'],
            'recurrence_days.*' => ['integer', 'between:1,7'],
            'assignee_user_ids' => ['nullable', 'array'],
            'assignee_user_ids.*' => ['integer'],
            'rotation_user_ids' => ['nullable', 'array'],
            'rotation_user_ids.*' => ['integer'],
            'is_rotation' => ['nullable', 'boolean'],
            'rotation_cycle_weeks' => ['nullable', 'integer', 'in:1,2'],
            'is_inter_household_alternating' => ['nullable', 'boolean'],
            'inter_household_week_start' => ['nullable', 'date_format:Y-m-d'],
            'fixed_user_id' => ['nullable', 'integer'],
        ]);

        $template = $this->upsertTaskTemplateAction->execute(
            household: $household,
            validated: $validated,
        );

        $taskTitle = (string) $template->name;
        $householdName = (string) ($household->name ?? 'ce foyer');
        $isRotation = (bool) $template->is_rotation;
        $fixedUserId = $template->fixed_user_id ? (int) $template->fixed_user_id : null;
        $assigneeUserIds = Normalization::memberIds($template->assignee_user_ids);
        $rotationUserIds = Normalization::memberIds($template->rotation_user_ids);
        $routineAssigneeIds = $isRotation ? $rotationUserIds : $assigneeUserIds;
        if (count($routineAssigneeIds) === 0 && $fixedUserId !== null) {
            $routineAssigneeIds = [(int) $fixedUserId];
        }

        $this->notificationService->notifyUsers(
            userIds: array_values(array_filter($routineAssigneeIds, static fn(int $id): bool => $id !== (int) $request->user()->id)),
            householdId: (int) $household->id,
            type: 'task_routine_assigned',
            title: 'Nouvelle routine attribuée',
            body: sprintf('La routine "%s" vous a été attribuée dans le foyer %s.', $taskTitle, $householdName),
            data: [
                'household_id' => (int) $household->id,
                'household_name' => $householdName,
                'task_template_id' => (int) $template->id,
                'task_name' => $taskTitle,
                'recurrence' => (string) $template->recurrence,
                'assigned_by_user_id' => (int) $request->user()->id,
                'assigned_by_name' => (string) ($request->user()->name ?? 'Un membre'),
                'assignee_ids' => $routineAssigneeIds,
            ],
        );

        $this->publishTasksRealtime(
            householdId: (int) $household->id,
            type: 'template.created',
            payload: [
                'template_id' => (int) $template->id,
                'name' => (string) $template->name,
                'recurrence' => (string) $template->recurrence,
            ],
        );

        return response()->json([
            'message' => 'Template de tâche créé.',
            'template' => [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'description' => $template->description,
                'recurrence' => (string) $template->recurrence,
                'start_date' => $this->resolveTemplateStartDateValue($template),
                'end_date' => optional($template->end_date)->toDateString(),
                'recurrence_days' => $this->normalizeRecurrenceDaysInput($template->recurrence_days),
                'assignee_user_ids' => Normalization::memberIds($template->assignee_user_ids),
                'rotation_user_ids' => Normalization::memberIds($template->rotation_user_ids),
                'is_rotation' => (bool) $template->is_rotation,
                'rotation_cycle_weeks' => max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1))),
                'is_inter_household_alternating' => (bool) ($template->is_inter_household_alternating ?? false),
                'inter_household_week_start' => optional($template->inter_household_week_start)->toDateString(),
                'fixed_user_id' => $template->fixed_user_id ? (int) $template->fixed_user_id : null,
                'fixed_user_name' => $template->fixedUser?->name,
            ],
        ], 201);
    }

    public function updateTemplate(Request $request, TaskTemplate $template): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureTasksModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTemplateBelongsToHousehold($template, $household);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'recurrence' => ['sometimes', 'required', 'in:daily,weekly,monthly,once'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'recurrence_days' => ['sometimes', 'nullable', 'array'],
            'recurrence_days.*' => ['integer', 'between:1,7'],
            'assignee_user_ids' => ['sometimes', 'nullable', 'array'],
            'assignee_user_ids.*' => ['integer'],
            'rotation_user_ids' => ['sometimes', 'nullable', 'array'],
            'rotation_user_ids.*' => ['integer'],
            'is_rotation' => ['sometimes', 'boolean'],
            'rotation_cycle_weeks' => ['sometimes', 'nullable', 'integer', 'in:1,2'],
            'is_inter_household_alternating' => ['sometimes', 'boolean'],
            'inter_household_week_start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'fixed_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $template = $this->upsertTaskTemplateAction->execute(
            household: $household,
            validated: $validated,
            template: $template,
        );

        $this->publishTasksRealtime(
            householdId: (int) $household->id,
            type: 'template.updated',
            payload: [
                'template_id' => (int) $template->id,
                'name' => (string) $template->name,
                'recurrence' => (string) $template->recurrence,
            ],
        );

        return response()->json([
            'message' => 'Template de tâche mis à jour.',
            'template' => [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'description' => $template->description,
                'recurrence' => (string) $template->recurrence,
                'start_date' => $this->resolveTemplateStartDateValue($template),
                'end_date' => optional($template->end_date)->toDateString(),
                'recurrence_days' => $this->normalizeRecurrenceDaysInput($template->recurrence_days),
                'assignee_user_ids' => Normalization::memberIds($template->assignee_user_ids),
                'rotation_user_ids' => Normalization::memberIds($template->rotation_user_ids),
                'is_rotation' => (bool) $template->is_rotation,
                'rotation_cycle_weeks' => max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1))),
                'is_inter_household_alternating' => (bool) ($template->is_inter_household_alternating ?? false),
                'inter_household_week_start' => optional($template->inter_household_week_start)->toDateString(),
                'fixed_user_id' => $template->fixed_user_id ? (int) $template->fixed_user_id : null,
                'fixed_user_name' => $template->fixedUser?->name,
            ],
        ]);
    }

    public function destroyTemplate(Request $request, TaskTemplate $template): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureTasksModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTemplateBelongsToHousehold($template, $household);

        $templateId = (int) $template->id;
        $templateName = (string) $template->name;
        $template->delete();

        $this->publishTasksRealtime(
            householdId: (int) $household->id,
            type: 'template.deleted',
            payload: [
                'template_id' => $templateId,
                'name' => $templateName,
            ],
        );

        return response()->json([
            'message' => 'Template de tâche supprimé.',
        ]);
    }

    public function requestInstanceReassignment(Request $request, TaskInstance $instance): JsonResponse
    {
        [$household] = $this->resolveHouseholdWithRole($request);
        $this->ensureTasksModuleEnabled($household);
        $this->ensureInstanceBelongsToHousehold($instance, $household);

        $validated = $request->validate([
            'invited_user_id' => ['required', 'integer'],
        ]);

        $currentUser = $request->user();
        $currentUserId = (int) $currentUser->id;
        $invitedUserId = $this->ensureUserBelongsToHousehold((int) $validated['invited_user_id'], $household);
        if ($invitedUserId === $currentUserId) {
            throw ValidationException::withMessages([
                'invited_user_id' => ['Sélectionnez un autre membre.'],
            ]);
        }

        $instance->loadMissing([
            'template:id,household_id,name',
            'assignees:id,name',
        ]);

        if (!$this->isUserAssignedToInstance($instance, $currentUserId)) {
            abort(403, 'Seul un membre assigné peut demander une reprise.');
        }

        if ($this->isUserAssignedToInstance($instance, $invitedUserId)) {
            throw ValidationException::withMessages([
                'invited_user_id' => ['Ce membre est déjà assigné à cette tâche.'],
            ]);
        }

        $taskTitle = (string) ($instance->template?->name ?? 'Tâche');
        $householdName = (string) ($household->name ?? 'ce foyer');
        $invitationNotification = DB::transaction(function () use (
            $household,
            $instance,
            $currentUser,
            $currentUserId,
            $invitedUserId,
            $taskTitle,
            $householdName
        ): UserNotification {
            $alreadyPending = UserNotification::query()
                ->where('user_id', $invitedUserId)
                ->where('type', 'task_reassignment_invite')
                ->where('data->status', 'pending')
                ->where('data->task_instance_id', (int) $instance->id)
                ->where('data->requester_user_id', $currentUserId)
                ->exists();

            if ($alreadyPending) {
                throw ValidationException::withMessages([
                    'invited_user_id' => ['Une demande est déjà en attente pour ce membre.'],
                ]);
            }

            return UserNotification::query()->create([
                'household_id' => $household->id,
                'user_id' => $invitedUserId,
                'type' => 'task_reassignment_invite',
                'title' => 'Demande de reprise de tâche',
                'body' => sprintf(
                    '%s vous demande de reprendre la tâche %s prévue le %s (foyer : %s).',
                    (string) ($currentUser->name ?? 'Un membre'),
                    $taskTitle,
                    optional($instance->due_date)->toDateString() ?? '',
                    $householdName
                ),
                'data' => [
                    'household_id' => (int) $household->id,
                    'household_name' => $householdName,
                    'task_instance_id' => (int) $instance->id,
                    'task_template_id' => (int) $instance->task_template_id,
                    'task_name' => $taskTitle,
                    'due_date' => optional($instance->due_date)->toDateString(),
                    'requester_user_id' => $currentUserId,
                    'requester_name' => (string) ($currentUser->name ?? 'Membre'),
                    'invited_user_id' => $invitedUserId,
                    'status' => 'pending',
                ],
            ]);
        });

        DB::afterCommit(function () use ($household, $invitationNotification, $invitedUserId): void {
            $this->realtimePublisher->publishUser(
                userId: $invitedUserId,
                module: 'notifications',
                type: 'task_reassignment_invite_created',
                payload: [
                    'notification_id' => (int) $invitationNotification->id,
                    'household_id' => (int) $household->id,
                    'household_name' => (string) data_get($invitationNotification->data, 'household_name', 'ce foyer'),
                    'task_instance_id' => (int) data_get($invitationNotification->data, 'task_instance_id'),
                    'task_name' => (string) data_get($invitationNotification->data, 'task_name', 'Tâche'),
                    'requester_user_id' => (int) data_get($invitationNotification->data, 'requester_user_id'),
                    'requester_name' => (string) data_get($invitationNotification->data, 'requester_name', 'Membre'),
                ],
            );

            $this->publishTasksRealtime(
                householdId: (int) $household->id,
                type: 'instance.reassignment_requested',
                payload: [
                    'task_instance_id' => (int) data_get($invitationNotification->data, 'task_instance_id'),
                    'task_name' => (string) data_get($invitationNotification->data, 'task_name', 'Tâche'),
                    'requester_user_id' => (int) data_get($invitationNotification->data, 'requester_user_id'),
                    'invited_user_id' => $invitedUserId,
                    'notification_id' => (int) $invitationNotification->id,
                ],
            );
        });

        return response()->json([
            'message' => 'Demande envoyée.',
            'invitation' => [
                'notification_id' => (int) $invitationNotification->id,
                'status' => (string) data_get($invitationNotification->data, 'status', 'pending'),
                'task_instance_id' => (int) data_get($invitationNotification->data, 'task_instance_id'),
                'invited_user_id' => $invitedUserId,
            ],
        ], 202);
    }

        public function storeInstance(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureTasksModuleEnabled($household);
        $isParent = $role === User::ROLE_PARENT;
        $currentUserId = (int) $request->user()->id;

        $validated = $request->validate([
            'task_template_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_date'],
            'user_id' => ['nullable', 'integer'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'status' => ['nullable', 'in:' . self::STATUS_TODO . ',' . self::STATUS_DONE . ',' . self::STATUS_CANCELLED],
        ]);

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
            if (!empty($validated['user_id']) && (int) $validated['user_id'] !== $currentUserId) {
                abort(403, 'Un enfant peut uniquement s attribuer ses tâches.');
            }

            $requestedIds = Normalization::memberIds($validated['user_ids'] ?? null);
            if (count($requestedIds) > 0 && !$this->memberIdsEquals($requestedIds, [$currentUserId])) {
                abort(403, 'Un enfant peut uniquement s attribuer ses tâches.');
            }

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

        $taskTitle = (string) ($instance->template?->name ?? 'Tâche');
        $householdName = (string) ($household->name ?? 'ce foyer');
        $notificationPayload = [
            'household_id' => (int) $household->id,
            'household_name' => $householdName,
            'task_instance_id' => (int) $instance->id,
            'task_template_id' => (int) $instance->task_template_id,
            'task_name' => $taskTitle,
            'due_date' => optional($instance->due_date)->toDateString(),
            'assigned_by_user_id' => $currentUserId,
            'assigned_by_name' => (string) ($request->user()->name ?? 'Un membre'),
        ];

        $this->notificationService->notifyUsers(
            userIds: array_values(array_filter($assigneeIds, static fn(int $id): bool => $id !== $currentUserId)),
            householdId: (int) $household->id,
            type: 'task_assigned',
            title: 'Nouvelle tâche assignée',
            body: sprintf('La tâche "%s" vous a été assignée dans le foyer %s.', $taskTitle, $householdName),
            data: $notificationPayload,
        );

        $allMemberIdsExceptActor = $household->users()
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0 && $id !== $currentUserId)
            ->values()
            ->all();
        $this->notificationService->notifyUsers(
            userIds: $allMemberIdsExceptActor,
            householdId: (int) $household->id,
            type: 'calendar_task_added',
            title: 'Tâche ajoutée au calendrier',
            body: sprintf('La tâche "%s" a été ajoutée dans le calendrier du foyer %s.', $taskTitle, $householdName),
            data: $notificationPayload + ['change' => 'added'],
        );

        $this->publishTasksRealtime(
            householdId: (int) $household->id,
            type: 'instance.upserted',
            payload: [
                'task_instance_id' => (int) $instance->id,
                'task_template_id' => (int) $instance->task_template_id,
                'task_name' => $taskTitle,
                'due_date' => optional($instance->due_date)->toDateString(),
                'assignee_ids' => $assigneeIds,
                'instance_ids' => array_values(array_map(static fn(TaskInstance $item): int => (int) $item->id, $instances)),
            ],
        );

        return response()->json([
            'message' => 'Tâche créée.',
            'instance' => $this->toInstancePayload($instance),
        ], 201);
    }

        public function updateInstance(Request $request, TaskInstance $instance): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureTasksModuleEnabled($household);
        $this->ensureInstanceBelongsToHousehold($instance, $household);

        $validated = $request->validate([
            'status' => ['sometimes', 'required', 'in:' . self::STATUS_TODO . ',' . self::STATUS_DONE . ',' . self::STATUS_CANCELLED],
            'due_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'user_ids' => ['sometimes', 'nullable', 'array'],
            'user_ids.*' => ['integer'],
            'validated_by_parent' => ['sometimes', 'boolean'],
        ]);

        $instance->loadMissing([
            'assignees:id,name',
            'template:id,household_id,name',
        ]);

        $previousStatus = (string) $instance->status;
        $previousValidatedByParent = (bool) $instance->validated_by_parent;
        $previousAssigneeIds = Normalization::memberIds(
            $instance->assignees
                ->map(static fn(User $assignee): int => (int) $assignee->id)
                ->values()
                ->all()
        );
        if (count($previousAssigneeIds) === 0 && (int) $instance->user_id > 0) {
            $previousAssigneeIds = [(int) $instance->user_id];
        }

        $isParent = $role === User::ROLE_PARENT;
        $currentUserId = (int) $request->user()->id;

        if (!$isParent) {
            if (!$this->isUserAssignedToInstance($instance, $currentUserId)) {
                abort(403, 'Vous pouvez modifier uniquement vos tâches.');
            }

            if (
                array_key_exists('user_id', $validated)
                || array_key_exists('user_ids', $validated)
                || array_key_exists('due_date', $validated)
                || array_key_exists('validated_by_parent', $validated)
            ) {
                abort(403, 'Action réservée aux parents.');
            }

            if (array_key_exists('status', $validated) && !in_array((string) $validated['status'], [self::STATUS_TODO, self::STATUS_DONE], true)) {
                abort(403, 'Statut non autorisé.');
            }
        }

        $updates = [];
        $nextAssigneeIds = null;

        if (array_key_exists('due_date', $validated)) {
            $updates['due_date'] = (string) $validated['due_date'];
        }

        if (array_key_exists('user_id', $validated) || array_key_exists('user_ids', $validated)) {
            if (!$isParent) {
                abort(403, 'Action réservée aux parents.');
            }

            $requestedIds = Normalization::memberIds($validated['user_ids'] ?? null);
            if (array_key_exists('user_id', $validated) && !empty($validated['user_id'])) {
                $requestedIds[] = (int) $validated['user_id'];
            }
            $requestedIds = Normalization::memberIds($requestedIds);
            $requestedIds = $this->ensureUsersBelongToHousehold($requestedIds, $household, 'user_ids');
            if (count($requestedIds) === 0) {
                throw ValidationException::withMessages([
                    'user_ids' => ['Sélectionnez au moins un membre à assigner.'],
                ]);
            }

            $nextAssigneeIds = $requestedIds;
            $updates['user_id'] = $this->resolvePrimaryAssigneeId($requestedIds);
        }

        if (array_key_exists('status', $validated)) {
            $instance = $this->toggleTaskStatusAction->execute(
                instance: $instance,
                status: (string) $validated['status'],
            );
        }

        if (array_key_exists('validated_by_parent', $validated)) {
            if (!$isParent) {
                abort(403, 'Action réservée aux parents.');
            }

            $shouldValidate = (bool) $validated['validated_by_parent'];
            if ($shouldValidate && (string) $instance->status !== self::STATUS_DONE) {
                throw ValidationException::withMessages([
                    'validated_by_parent' => ['Seule une tâche réalisée peut être validée.'],
                ]);
            }
            $updates['validated_by_parent'] = $shouldValidate;
        }

        if (count($updates) > 0) {
            $instance->update($updates);
        }

        if (is_array($nextAssigneeIds)) {
            $this->syncInstanceAssignees($instance, $nextAssigneeIds);
        }

        $instance->load([
            'template:id,household_id,name,description,recurrence,start_date,end_date,recurrence_days,assignee_user_ids,rotation_user_ids,is_rotation,rotation_cycle_weeks,is_inter_household_alternating,inter_household_week_start,fixed_user_id',
            'user:id,name',
            'assignees:id,name',
        ]);

        $currentAssigneeIds = Normalization::memberIds(
            $instance->assignees
                ->map(static fn(User $assignee): int => (int) $assignee->id)
                ->values()
                ->all()
        );
        if (count($currentAssigneeIds) === 0 && (int) $instance->user_id > 0) {
            $currentAssigneeIds = [(int) $instance->user_id];
        }

        $taskTitle = (string) ($instance->template?->name ?? 'Tâche');
        $householdName = (string) ($household->name ?? 'ce foyer');
        $sharedPayload = [
            'household_id' => (int) $household->id,
            'household_name' => $householdName,
            'task_instance_id' => (int) $instance->id,
            'task_template_id' => (int) $instance->task_template_id,
            'task_name' => $taskTitle,
            'due_date' => optional($instance->due_date)->toDateString(),
            'actor_user_id' => $currentUserId,
            'actor_name' => (string) ($request->user()->name ?? 'Un membre'),
        ];

        if ($previousStatus !== self::STATUS_DONE && (string) $instance->status === self::STATUS_DONE) {
            $this->notificationService->notifyUsers(
                userIds: array_values(array_filter($this->resolveParentUserIds($household), static fn(int $id): bool => $id !== $currentUserId)),
                householdId: (int) $household->id,
                type: 'task_done_validation_needed',
                title: 'Validation de tâche requise',
                body: sprintf('La tâche "%s" a été marquée réalisée dans le foyer %s.', $taskTitle, $householdName),
                data: $sharedPayload,
            );
        }

        if (!$previousValidatedByParent && (bool) $instance->validated_by_parent) {
            $this->notificationService->notifyUsers(
                userIds: $currentAssigneeIds,
                householdId: (int) $household->id,
                type: 'task_validated',
                title: 'Tâche validée',
                body: sprintf('La tâche "%s" a été validée par un parent dans le foyer %s.', $taskTitle, $householdName),
                data: $sharedPayload,
            );
        }

        if ($previousStatus !== self::STATUS_CANCELLED && (string) $instance->status === self::STATUS_CANCELLED) {
            $this->notificationService->notifyUsers(
                userIds: $currentAssigneeIds,
                householdId: (int) $household->id,
                type: 'task_cancelled',
                title: 'Tâche annulée',
                body: sprintf('La tâche "%s" a été annulée dans le foyer %s.', $taskTitle, $householdName),
                data: $sharedPayload,
            );
        }

        if (!$this->memberIdsEquals($previousAssigneeIds, $currentAssigneeIds)) {
            $this->notificationService->notifyUsers(
                userIds: array_values(array_filter($currentAssigneeIds, static fn(int $id): bool => $id !== $currentUserId)),
                householdId: (int) $household->id,
                type: 'task_reassigned',
                title: 'Réattribution de tâche',
                body: sprintf('La tâche "%s" vous a été réattribuée dans le foyer %s.', $taskTitle, $householdName),
                data: $sharedPayload + [
                    'previous_assignee_ids' => $previousAssigneeIds,
                    'assignee_ids' => $currentAssigneeIds,
                ],
            );
        }

        $allMemberIdsExceptActor = $household->users()
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0 && $id !== $currentUserId)
            ->values()
            ->all();
        $statusNow = (string) $instance->status;
        $isDeletion = $previousStatus !== self::STATUS_CANCELLED && $statusNow === self::STATUS_CANCELLED;
        $this->notificationService->notifyUsers(
            userIds: $allMemberIdsExceptActor,
            householdId: (int) $household->id,
            type: $isDeletion ? 'calendar_task_deleted' : 'calendar_task_updated',
            title: $isDeletion ? 'Tâche supprimée du calendrier' : 'Tâche modifiée',
            body: $isDeletion
                ? sprintf('La tâche "%s" a été supprimée du calendrier du foyer %s.', $taskTitle, $householdName)
                : sprintf('La tâche "%s" a été modifiée dans le calendrier du foyer %s.', $taskTitle, $householdName),
            data: $sharedPayload + ['change' => $isDeletion ? 'deleted' : 'updated'],
        );

        $this->publishTasksRealtime(
            householdId: (int) $household->id,
            type: 'instance.updated',
            payload: $sharedPayload + [
                'status' => (string) $instance->status,
                'validated_by_parent' => (bool) $instance->validated_by_parent,
                'assignee_ids' => $currentAssigneeIds,
            ],
        );

        return response()->json([
            'message' => 'Tâche mise à jour.',
            'instance' => $this->toInstancePayload($instance),
        ]);
    }

    public function validateInstance(Request $request, TaskInstance $instance): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureTasksModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureInstanceBelongsToHousehold($instance, $household);

        if ((string) $instance->status !== self::STATUS_DONE) {
            throw ValidationException::withMessages([
                'status' => ['La tâche doit être réalisée avant validation.'],
            ]);
        }

        $instance->update([
            'validated_by_parent' => true,
        ]);

        $instance->load([
            'template:id,household_id,name,description,recurrence,start_date,end_date,recurrence_days,assignee_user_ids,rotation_user_ids,is_rotation,rotation_cycle_weeks,is_inter_household_alternating,inter_household_week_start,fixed_user_id',
            'user:id,name',
            'assignees:id,name',
        ]);

        $assigneeIds = Normalization::memberIds(
            $instance->assignees
                ->map(static fn(User $assignee): int => (int) $assignee->id)
                ->values()
                ->all()
        );
        if (count($assigneeIds) === 0 && (int) $instance->user_id > 0) {
            $assigneeIds = [(int) $instance->user_id];
        }

        $taskTitle = (string) ($instance->template?->name ?? 'Tâche');
        $householdName = (string) ($household->name ?? 'ce foyer');
        $payload = [
            'household_id' => (int) $household->id,
            'household_name' => $householdName,
            'task_instance_id' => (int) $instance->id,
            'task_template_id' => (int) $instance->task_template_id,
            'task_name' => $taskTitle,
            'due_date' => optional($instance->due_date)->toDateString(),
            'validated_by_user_id' => (int) $request->user()->id,
            'validated_by_name' => (string) ($request->user()->name ?? 'Parent'),
        ];

        $this->notificationService->notifyUsers(
            userIds: $assigneeIds,
            householdId: (int) $household->id,
            type: 'task_validated',
            title: 'Tâche validée',
            body: sprintf('La tâche "%s" a été validée dans le foyer %s.', $taskTitle, $householdName),
            data: $payload,
        );

        $actorId = (int) $request->user()->id;
        $allMemberIdsExceptActor = $household->users()
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0 && $id !== $actorId)
            ->values()
            ->all();
        $this->notificationService->notifyUsers(
            userIds: $allMemberIdsExceptActor,
            householdId: (int) $household->id,
            type: 'calendar_task_updated',
            title: 'Tâche modifiée',
            body: sprintf('La tâche "%s" a été modifiée dans le calendrier du foyer %s.', $taskTitle, $householdName),
            data: $payload + ['change' => 'updated'],
        );

        $this->publishTasksRealtime(
            householdId: (int) $household->id,
            type: 'instance.validated',
            payload: $payload + [
                'assignee_ids' => $assigneeIds,
            ],
        );

        return response()->json([
            'message' => 'Tâche validée.',
            'instance' => $this->toInstancePayload($instance),
        ]);
    }

    private function toInstancePayload(TaskInstance $instance): array
    {
        return [
            'id' => (int) $instance->id,
            'task_template_id' => (int) $instance->task_template_id,
            'title' => (string) ($instance->template?->name ?? 'Tâche'),
            'description' => $instance->template?->description,
            'due_date' => optional($instance->due_date)->toDateString(),
            'status' => (string) $instance->status,
            'completed_at' => optional($instance->completed_at)->toIso8601String(),
            'validated_by_parent' => (bool) $instance->validated_by_parent,
            'assignee' => [
                'id' => (int) ($instance->user?->id ?? 0),
                'name' => (string) ($instance->user?->name ?? 'Membre'),
            ],
            'assignees' => $instance->assignees
                ->sortBy('id')
                ->map(static fn(User $user): array => [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                ])
                ->values()
                ->all(),
            'template' => [
                'id' => (int) ($instance->template?->id ?? 0),
                'recurrence' => (string) ($instance->template?->recurrence ?? 'once'),
                'start_date' => $this->resolveTemplateStartDateValue($instance->template),
                'end_date' => optional($instance->template?->end_date)->toDateString(),
                'recurrence_days' => $this->normalizeRecurrenceDaysInput($instance->template?->recurrence_days),
                'assignee_user_ids' => Normalization::memberIds($instance->template?->assignee_user_ids),
                'rotation_user_ids' => Normalization::memberIds($instance->template?->rotation_user_ids),
                'is_rotation' => (bool) ($instance->template?->is_rotation ?? false),
                'rotation_cycle_weeks' => max(1, min(2, (int) ($instance->template?->rotation_cycle_weeks ?? 1))),
                'is_inter_household_alternating' => (bool) ($instance->template?->is_inter_household_alternating ?? false),
                'inter_household_week_start' => optional($instance->template?->inter_household_week_start)->toDateString(),
            ],
        ];
    }

    private function publishTasksRealtime(int $householdId, string $type, array $payload = []): void
    {
        $this->realtimePublisher->publishHousehold(
            householdId: $householdId,
            module: 'tasks',
            type: $type,
            payload: $payload + ['household_id' => $householdId],
        );
    }

    /**
     * @return array<int, int>
     */
    private function resolveParentUserIds(Household $household): array
    {
        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    private function isTasksModuleEnabled(Household $household): bool
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();

        return (bool) ($settings?->has_tasks ?? false);
    }

    private function ensureTasksModuleEnabled(Household $household): void
    {
        if (!$this->isTasksModuleEnabled($household)) {
            abort(403, 'Le module tâches est désactivé pour ce foyer.');
        }
    }

    private function ensureTemplateBelongsToHousehold(TaskTemplate $template, Household $household): void
    {
        if ((int) $template->household_id !== (int) $household->id) {
            abort(404, 'Template de tâche introuvable.');
        }
    }

    private function ensureInstanceBelongsToHousehold(TaskInstance $instance, Household $household): void
    {
        $belongs = TaskTemplate::query()
            ->where('id', $instance->task_template_id)
            ->where('household_id', $household->id)
            ->exists();

        if (!$belongs) {
            abort(404, 'Tâche introuvable.');
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
     * @return array{enabled:bool,change_day:int,home_week_start:string|null}
     */
    private function resolveAlternatingCustodySettings(Household $household): array
    {
        $settings = HouseholdSetting::query()
            ->where('household_id', $household->id)
            ->first();
        $tasksConfig = is_array($settings?->tasks_config) ? $settings->tasks_config : [];
        $enabled = (bool) ($tasksConfig['alternating_custody_enabled'] ?? false);
        $changeDay = Normalization::isoWeekDay($tasksConfig['custody_change_day'] ?? 5, 5);
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

    private function isAlternatingCustodyEnabledForChildAssignee(
        array $alternatingCustody,
        Collection $members,
        int $assigneeId
    ): bool {
        if (!(bool) ($alternatingCustody['enabled'] ?? false)) {
            return false;
        }

        $member = $members->first(
            static fn(array $candidate): bool => (int) ($candidate['id'] ?? 0) === $assigneeId
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

    private function ensureRecurringInstances(
        Collection $templates,
        Collection $members,
        Carbon $fromDate,
        Carbon $toDate,
        array $alternatingCustody,
        int $householdId,
        int $interHouseholdWeekStartDay
    ): void
    {
        if ($members->isEmpty() || $templates->isEmpty()) {
            return;
        }

        $templateIds = $templates
            ->map(static fn(TaskTemplate $template): int => (int) $template->id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();

        if (count($templateIds) === 0) {
            return;
        }

        $periodDays = collect(CarbonPeriod::create($fromDate->copy(), '1 day', $toDate->copy()))
            ->map(static fn(Carbon $day): Carbon => $day->copy()->startOfDay())
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
                        if (
                            !$this->isAlternatingCustodyEnabledForChildAssignee($alternatingCustody, $members, $assigneeId)
                        ) {
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
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
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
    ): bool
    {
        $anchor = $this->resolveTemplateAnchorDate($template, $date);
        $startDate = $template->start_date
            ? Carbon::parse($template->start_date)->startOfDay()
            : null;
        $endDate = $template->end_date
            ? Carbon::parse($template->end_date)->startOfDay()
            : null;
        $recurrence = (string) ($template->recurrence ?? 'daily');
        $recurrenceDays = $this->normalizeRecurrenceDaysInput($template->recurrence_days);

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
    ): bool
    {
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

    private function resolvePrimaryAssigneeId(array $assigneeIds): int
    {
        return (int) (Normalization::memberIds($assigneeIds)[0] ?? 0);
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

    private function resolveTemplateAnchorDate(TaskTemplate $template, Carbon $fallbackDate): Carbon
    {
        $startDate = $this->resolveTemplateStartDateValue($template);
        if (is_string($startDate) && trim($startDate) !== '') {
            return Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
        }

        if ($template->created_at) {
            return Carbon::parse($template->created_at)->startOfDay();
        }

        return $fallbackDate->copy()->startOfDay();
    }

    private function resolveInterHouseholdWeekStart(
        bool $isEnabled,
        mixed $rawWeekStart,
        int $weekStartDay
    ): ?string
    {
        if (!$isEnabled) {
            return null;
        }

        $startDate = is_string($rawWeekStart) && trim($rawWeekStart) !== ''
            ? Carbon::createFromFormat('Y-m-d', trim($rawWeekStart))->startOfDay()
            : now()->startOfDay();
        $normalizedWeekStartDay = Normalization::isoWeekDay($weekStartDay, 1);

        return $this->startOfCustomWeek($startDate, $normalizedWeekStartDay)->toDateString();
    }

    private function resolveInterHouseholdWeekStartDay(array $alternatingCustody): int
    {
        if (!(bool) ($alternatingCustody['enabled'] ?? false)) {
            return 1;
        }

        return Normalization::isoWeekDay($alternatingCustody['change_day'] ?? 1, 1);
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
            if (count($days) === 0) {
                return ['daily', self::FULL_WEEK_DAYS];
            }

            if (count($days) === 7) {
                return ['daily', self::FULL_WEEK_DAYS];
            }

            return ['weekly', $days];
        }

        if (count($days) === 7) {
            return ['daily', self::FULL_WEEK_DAYS];
        }

        return ['weekly', $days];
    }

    /**
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

    private function memberIdsEquals(array $left, array $right): bool
    {
        $leftNormalized = Normalization::memberIds($left);
        $rightNormalized = Normalization::memberIds($right);
        sort($leftNormalized);
        sort($rightNormalized);

        return $leftNormalized === $rightNormalized;
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
                    $field => ['Le membre sélectionné n appartient pas au foyer.'],
                ]);
            }
        }

        return $normalizedIds;
    }

    private function isUserAssignedToInstance(TaskInstance $instance, int $userId): bool
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


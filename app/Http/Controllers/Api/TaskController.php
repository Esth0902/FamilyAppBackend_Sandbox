<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\TaskInstance;
use App\Models\TaskTemplate;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    private const STATUS_TODO = 'à faire';
    private const STATUS_DONE = 'réalisée';
    private const STATUS_CANCELLED = 'annulée';
    private const DEFAULT_RANGE_DAYS = 14;
    private const MAX_RANGE_DAYS = 45;

    public function board(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        [$fromDate, $toDate] = $this->resolveDateRange($request);

        $tasksEnabled = $this->isTasksModuleEnabled($household);
        $members = $this->resolveHouseholdMembers($household);

        $templates = TaskTemplate::query()
            ->where('household_id', $household->id)
            ->with('fixedUser:id,name')
            ->orderBy('id')
            ->get();

        if ($tasksEnabled) {
            $this->ensureRecurringInstances($templates, $members, $fromDate, $toDate);
        }

        $instances = TaskInstance::query()
            ->whereHas('template', fn($query) => $query->where('household_id', $household->id))
            ->whereBetween('due_date', [$fromDate->toDateString(), $toDate->toDateString()])
            ->with([
                'template:id,household_id,name,description,recurrence,recurrence_days,is_rotation,rotation_cycle_weeks,fixed_user_id',
                'user:id,name',
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
            'can_manage_templates' => $role === User::ROLE_PARENT,
            'can_manage_instances' => $role === User::ROLE_PARENT,
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
                    'recurrence_days' => $this->normalizeRecurrenceDaysInput($template->recurrence_days),
                    'is_rotation' => (bool) $template->is_rotation,
                    'rotation_cycle_weeks' => max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1))),
                    'fixed_user_id' => $template->fixed_user_id ? (int) $template->fixed_user_id : null,
                    'fixed_user_name' => $template->fixedUser?->name,
                ];
            })->values(),
            'instances' => $instances->map(function (TaskInstance $instance) use ($currentUserId, $role): array {
                $isParent = $role === User::ROLE_PARENT;
                $isAssignedUser = (int) $instance->user_id === $currentUserId;
                $status = (string) $instance->status;

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
                    'template' => [
                        'id' => (int) ($instance->template?->id ?? 0),
                        'recurrence' => (string) ($instance->template?->recurrence ?? 'once'),
                        'recurrence_days' => $this->normalizeRecurrenceDaysInput($instance->template?->recurrence_days),
                        'is_rotation' => (bool) ($instance->template?->is_rotation ?? false),
                        'rotation_cycle_weeks' => max(1, min(2, (int) ($instance->template?->rotation_cycle_weeks ?? 1))),
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
            'recurrence_days' => ['nullable', 'array'],
            'recurrence_days.*' => ['integer', 'between:1,7'],
            'is_rotation' => ['nullable', 'boolean'],
            'rotation_cycle_weeks' => ['nullable', 'integer', 'in:1,2'],
            'fixed_user_id' => ['nullable', 'integer'],
        ]);

        $fixedUserId = $validated['fixed_user_id'] ?? null;
        if ($fixedUserId !== null) {
            $this->ensureUserBelongsToHousehold((int) $fixedUserId, $household);
        }

        $recurrence = (string) $validated['recurrence'];
        $recurrenceDays = $this->normalizeRecurrenceDaysInput($validated['recurrence_days'] ?? null);
        if (!in_array($recurrence, ['daily', 'weekly'], true)) {
            $recurrenceDays = [];
        }

        $isRotation = (bool) ($validated['is_rotation'] ?? false);

        $template = TaskTemplate::query()->create([
            'household_id' => $household->id,
            'name' => trim((string) $validated['name']),
            'description' => $validated['description'] ?? null,
            'recurrence' => $recurrence,
            'recurrence_days' => count($recurrenceDays) > 0 ? $recurrenceDays : null,
            'is_rotation' => $isRotation,
            'rotation_cycle_weeks' => $isRotation
                ? max(1, min(2, (int) ($validated['rotation_cycle_weeks'] ?? 1)))
                : 1,
            'fixed_user_id' => $fixedUserId ? (int) $fixedUserId : null,
        ])->load('fixedUser:id,name');

        return response()->json([
            'message' => 'Template de tâche créé.',
            'template' => [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'description' => $template->description,
                'recurrence' => (string) $template->recurrence,
                'recurrence_days' => $this->normalizeRecurrenceDaysInput($template->recurrence_days),
                'is_rotation' => (bool) $template->is_rotation,
                'rotation_cycle_weeks' => max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1))),
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
            'recurrence_days' => ['sometimes', 'nullable', 'array'],
            'recurrence_days.*' => ['integer', 'between:1,7'],
            'is_rotation' => ['sometimes', 'boolean'],
            'rotation_cycle_weeks' => ['sometimes', 'nullable', 'integer', 'in:1,2'],
            'fixed_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

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
            $recurrenceDays = $this->normalizeRecurrenceDaysInput($validated['recurrence_days'] ?? null);
            $recurrenceValue = (string) ($updates['recurrence'] ?? $template->recurrence ?? 'daily');
            $updates['recurrence_days'] = in_array($recurrenceValue, ['daily', 'weekly'], true) && count($recurrenceDays) > 0
                ? $recurrenceDays
                : null;
        }
        if (array_key_exists('is_rotation', $validated)) {
            $updates['is_rotation'] = (bool) $validated['is_rotation'];
        }
        if (array_key_exists('rotation_cycle_weeks', $validated)) {
            $updates['rotation_cycle_weeks'] = max(1, min(2, (int) ($validated['rotation_cycle_weeks'] ?? 1)));
        }
        if (array_key_exists('fixed_user_id', $validated)) {
            $updates['fixed_user_id'] = $validated['fixed_user_id'] ? (int) $validated['fixed_user_id'] : null;
        }

        if (($updates['is_rotation'] ?? $template->is_rotation) === false) {
            $updates['rotation_cycle_weeks'] = 1;
        }

        if (array_key_exists('recurrence', $updates) && !in_array((string) $updates['recurrence'], ['daily', 'weekly'], true)) {
            $updates['recurrence_days'] = null;
        }

        if (count($updates) > 0) {
            $template->update($updates);
        }

        $template->load('fixedUser:id,name');

        return response()->json([
            'message' => 'Template de tâche mis à jour.',
            'template' => [
                'id' => (int) $template->id,
                'name' => (string) $template->name,
                'description' => $template->description,
                'recurrence' => (string) $template->recurrence,
                'recurrence_days' => $this->normalizeRecurrenceDaysInput($template->recurrence_days),
                'is_rotation' => (bool) $template->is_rotation,
                'rotation_cycle_weeks' => max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1))),
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

        $template->delete();

        return response()->json([
            'message' => 'Template de tâche supprimé.',
        ]);
    }

    public function storeInstance(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureTasksModuleEnabled($household);
        $this->ensureParentRole($role);

        $validated = $request->validate([
            'task_template_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'user_id' => ['nullable', 'integer'],
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
                'recurrence_days' => null,
                'is_rotation' => false,
                'rotation_cycle_weeks' => 1,
                'fixed_user_id' => null,
            ]);
        }

        $members = $this->resolveHouseholdMembers($household);
        $dueDate = Carbon::createFromFormat('Y-m-d', (string) $validated['due_date'])->startOfDay();

        $assigneeId = null;
        if (!empty($validated['user_id'])) {
            $assigneeId = $this->ensureUserBelongsToHousehold((int) $validated['user_id'], $household);
        } else {
            $assigneeId = $this->resolveAssigneeId($template, $members, $dueDate);
        }

        if (!$assigneeId) {
            throw ValidationException::withMessages([
                'user_id' => ['Impossible de déterminer un membre à assigner.'],
            ]);
        }

        $status = (string) ($validated['status'] ?? self::STATUS_TODO);

        $instance = TaskInstance::query()
            ->where('task_template_id', (int) $template->id)
            ->whereDate('due_date', $dueDate->toDateString())
            ->orderBy('id')
            ->first();

        if ($instance) {
            if (
                (int) $instance->user_id !== (int) $assigneeId
                && (string) $instance->status === self::STATUS_TODO
                && !$instance->validated_by_parent
            ) {
                $instance->update(['user_id' => (int) $assigneeId]);
            }
        } else {
            $instance = TaskInstance::query()->create([
                'task_template_id' => (int) $template->id,
                'user_id' => (int) $assigneeId,
                'due_date' => $dueDate->toDateString(),
                'status' => $status,
                'completed_at' => $status === self::STATUS_DONE ? now() : null,
                'validated_by_parent' => false,
            ]);
        }

        $instance->load(['template:id,household_id,name,description,recurrence,recurrence_days,is_rotation,rotation_cycle_weeks,fixed_user_id', 'user:id,name']);

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
            'user_id' => ['sometimes', 'required', 'integer'],
            'validated_by_parent' => ['sometimes', 'boolean'],
        ]);

        $isParent = $role === User::ROLE_PARENT;
        $currentUserId = (int) $request->user()->id;

        if (!$isParent) {
            if ((int) $instance->user_id !== $currentUserId) {
                abort(403, 'Vous pouvez modifier uniquement vos tâches.');
            }

            if (array_key_exists('user_id', $validated) || array_key_exists('due_date', $validated) || array_key_exists('validated_by_parent', $validated)) {
                abort(403, 'Action réservée aux parents.');
            }

            if (array_key_exists('status', $validated) && !in_array((string) $validated['status'], [self::STATUS_TODO, self::STATUS_DONE], true)) {
                abort(403, 'Statut non autorisé.');
            }
        }

        $updates = [];

        if (array_key_exists('due_date', $validated)) {
            $updates['due_date'] = (string) $validated['due_date'];
        }

        if (array_key_exists('user_id', $validated)) {
            if (!$isParent) {
                abort(403, 'Action réservée aux parents.');
            }

            $userId = $this->ensureUserBelongsToHousehold((int) $validated['user_id'], $household);
            $updates['user_id'] = $userId;
        }

        if (array_key_exists('status', $validated)) {
            $nextStatus = (string) $validated['status'];
            $updates['status'] = $nextStatus;
            if ($nextStatus === self::STATUS_DONE) {
                $updates['completed_at'] = $instance->completed_at ?? now();
            } else {
                $updates['completed_at'] = null;
                $updates['validated_by_parent'] = false;
            }
        }

        if (array_key_exists('validated_by_parent', $validated)) {
            if (!$isParent) {
                abort(403, 'Action réservée aux parents.');
            }

            $shouldValidate = (bool) $validated['validated_by_parent'];
            if ($shouldValidate && ((string) ($updates['status'] ?? $instance->status)) !== self::STATUS_DONE) {
                throw ValidationException::withMessages([
                    'validated_by_parent' => ['Seule une tâche réalisée peut être validée.'],
                ]);
            }
            $updates['validated_by_parent'] = $shouldValidate;
        }

        if (count($updates) > 0) {
            $instance->update($updates);
        }

        $instance->load([
            'template:id,household_id,name,description,recurrence,recurrence_days,is_rotation,rotation_cycle_weeks,fixed_user_id',
            'user:id,name',
        ]);

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
            'template:id,household_id,name,description,recurrence,recurrence_days,is_rotation,rotation_cycle_weeks,fixed_user_id',
            'user:id,name',
        ]);

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
            'template' => [
                'id' => (int) ($instance->template?->id ?? 0),
                'recurrence' => (string) ($instance->template?->recurrence ?? 'once'),
                'recurrence_days' => $this->normalizeRecurrenceDaysInput($instance->template?->recurrence_days),
                'is_rotation' => (bool) ($instance->template?->is_rotation ?? false),
                'rotation_cycle_weeks' => max(1, min(2, (int) ($instance->template?->rotation_cycle_weeks ?? 1))),
            ],
        ];
    }

    private function resolveHouseholdWithRole(Request $request): array
    {
        $household = $request->user()->households()->first();
        if (!$household) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associé à cet utilisateur.'],
            ]);
        }

        $role = (string) ($household->pivot->role ?? User::ROLE_CHILD);

        return [$household, $role];
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

    private function ensureParentRole(string $role): void
    {
        if ($role !== User::ROLE_PARENT) {
            abort(403, 'Action réservée aux parents.');
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

    private function resolveDateRange(Request $request): array
    {
        $fromInput = (string) ($request->query('from') ?? now()->toDateString());
        $toInput = (string) ($request->query('to') ?? now()->copy()->addDays(self::DEFAULT_RANGE_DAYS - 1)->toDateString());

        try {
            $fromDate = Carbon::createFromFormat('Y-m-d', $fromInput);
            $toDate = Carbon::createFromFormat('Y-m-d', $toInput);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'dates' => ['Renseignez une date de début et de fin valides (YYYY-MM-DD).'],
            ]);
        }

        if (!$fromDate || $fromDate->toDateString() !== $fromInput || !$toDate || $toDate->toDateString() !== $toInput) {
            throw ValidationException::withMessages([
                'dates' => ['Renseignez une date de début et de fin valides (YYYY-MM-DD).'],
            ]);
        }

        $fromDate = $fromDate->startOfDay();
        $toDate = $toDate->startOfDay();

        if ($toDate->lt($fromDate)) {
            throw ValidationException::withMessages([
                'dates' => ['La date de fin doit être supérieure ou égale à la date de début.'],
            ]);
        }

        $rangeDays = $fromDate->diffInDays($toDate) + 1;
        if ($rangeDays > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'dates' => ['La période demandée est trop longue.'],
            ]);
        }

        return [$fromDate, $toDate];
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

    private function ensureRecurringInstances(Collection $templates, Collection $members, Carbon $fromDate, Carbon $toDate): void
    {
        if ($members->isEmpty() || $templates->isEmpty()) {
            return;
        }

        foreach ($templates as $template) {
            if ((string) $template->recurrence === 'once') {
                continue;
            }

            $period = CarbonPeriod::create($fromDate->copy(), '1 day', $toDate->copy());
            foreach ($period as $day) {
                $date = $day->copy()->startOfDay();
                if (!$this->templateAppliesToDate($template, $date)) {
                    continue;
                }

                $assigneeId = $this->resolveAssigneeId($template, $members, $date);
                if (!$assigneeId) {
                    continue;
                }

                $existing = TaskInstance::query()
                    ->where('task_template_id', (int) $template->id)
                    ->whereDate('due_date', $date->toDateString())
                    ->orderBy('id')
                    ->first();

                if ($existing) {
                    if (
                        (int) $existing->user_id !== (int) $assigneeId
                        && (string) $existing->status === self::STATUS_TODO
                        && !$existing->validated_by_parent
                    ) {
                        $existing->update(['user_id' => (int) $assigneeId]);
                    }
                    continue;
                }

                TaskInstance::query()->create([
                    'task_template_id' => (int) $template->id,
                    'user_id' => (int) $assigneeId,
                    'due_date' => $date->toDateString(),
                    'status' => self::STATUS_TODO,
                    'validated_by_parent' => false,
                ]);
            }
        }
    }

    private function templateAppliesToDate(TaskTemplate $template, Carbon $date): bool
    {
        $anchor = $template->created_at ? Carbon::parse($template->created_at)->startOfDay() : $date;
        $recurrence = (string) ($template->recurrence ?? 'daily');
        $recurrenceDays = $this->normalizeRecurrenceDaysInput($template->recurrence_days);

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

    private function resolveAssigneeId(TaskTemplate $template, Collection $members, Carbon $date): ?int
    {
        if (
            !$template->is_rotation
            && $template->fixed_user_id
            && $members->contains(fn(array $member): bool => (int) $member['id'] === (int) $template->fixed_user_id)
        ) {
            return (int) $template->fixed_user_id;
        }

        $childMembers = $members->filter(static fn(array $member): bool => (string) $member['role'] === User::ROLE_CHILD)->values();
        $pool = $childMembers->isNotEmpty() ? $childMembers : $members;

        if ($pool->isEmpty()) {
            return null;
        }

        if ((bool) $template->is_rotation) {
            $poolValues = $pool->values();
            $poolCount = $poolValues->count();

            $startIndex = ((int) $template->id) % $poolCount;
            if ($template->fixed_user_id) {
                $matchedIndex = $poolValues->search(
                    static fn(array $member): bool => (int) $member['id'] === (int) $template->fixed_user_id
                );
                if ($matchedIndex !== false) {
                    $startIndex = (int) $matchedIndex;
                }
            }

            $anchorWeek = ($template->created_at ? Carbon::parse($template->created_at) : $date)
                ->startOfWeek(Carbon::MONDAY);
            $targetWeek = $date->copy()->startOfWeek(Carbon::MONDAY);
            $weeksFromAnchor = max(0, (int) $anchorWeek->diffInWeeks($targetWeek));

            $cycleWeeks = max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1)));
            $rotationOffset = (int) floor($weeksFromAnchor / $cycleWeeks);

            $assigneeIndex = ($startIndex + $rotationOffset) % $poolCount;
            return (int) ($poolValues->get($assigneeIndex)['id'] ?? 0);
        }

        return (int) ($pool->first()['id'] ?? 0);
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
}

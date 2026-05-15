<?php

namespace App\Services;

use App\Models\TaskInstance;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TaskNotificationScheduler
{
    public function __construct(
        private readonly RealtimePublisher $realtimePublisher,
    ) {
    }

    public function run(): void
    {
        $now = now();
        $windowStart = $now->copy()->subMinutes(5);

        $this->processDailyMorningReminders($now, $windowStart);
        $this->processDailyEveningReminders($now, $windowStart);
        $this->processNonDailyEveningReminders($now, $windowStart);
        $this->processOverdueTasks($now, $windowStart);
    }

    private function processDailyMorningReminders(Carbon $now, Carbon $windowStart): void
    {
        if (!$this->isWithinTimeWindow($now, $windowStart, '09:00')) {
            return;
        }

        TaskInstance::query()
            ->with(['template.household.users', 'user', 'assignees'])
            ->whereDate('due_date', $now->toDateString())
            ->whereNull('completed_at')
            ->chunkById(100, function (Collection $instances): void {
                foreach ($instances as $instance) {
                    if ($this->resolveRecurrence($instance) !== 'daily') {
                        continue;
                    }

                    $this->notifyAssigneesOnce(
                        $instance,
                        'task_due_today_morning',
                        'Rappel de tâche',
                        fn (string $taskName, string $dueDate): string =>
                            sprintf('La tâche "%s" est prévue aujourd’hui (%s).', $taskName, $dueDate)
                    );
                }
            });
    }

    private function processDailyEveningReminders(Carbon $now, Carbon $windowStart): void
    {
        if (!$this->isWithinTimeWindow($now, $windowStart, '18:00')) {
            return;
        }

        TaskInstance::query()
            ->with(['template.household.users', 'user', 'assignees'])
            ->whereDate('due_date', $now->toDateString())
            ->whereNull('completed_at')
            ->chunkById(100, function (Collection $instances): void {
                foreach ($instances as $instance) {
                    if ($this->resolveRecurrence($instance) !== 'daily') {
                        continue;
                    }

                    $this->notifyAssigneesOnce(
                        $instance,
                        'task_due_today_evening',
                        'Tâche à terminer ce soir',
                        fn (string $taskName, string $dueDate): string =>
                            sprintf('La tâche "%s" doit être terminée aujourd’hui (%s).', $taskName, $dueDate)
                    );
                }
            });
    }

    private function processNonDailyEveningReminders(Carbon $now, Carbon $windowStart): void
    {
        if (!$this->isWithinTimeWindow($now, $windowStart, '18:00')) {
            return;
        }

        $tomorrow = $now->copy()->addDay()->toDateString();

        TaskInstance::query()
            ->with(['template.household.users', 'user', 'assignees'])
            ->whereDate('due_date', $tomorrow)
            ->whereNull('completed_at')
            ->chunkById(100, function (Collection $instances): void {
                foreach ($instances as $instance) {
                    $recurrence = $this->resolveRecurrence($instance);

                    if (!in_array($recurrence, ['weekly', 'monthly', 'once'], true)) {
                        continue;
                    }

                    $this->notifyAssigneesOnce(
                        $instance,
                        'task_due_tomorrow',
                        'Rappel de tâche',
                        fn (string $taskName, string $dueDate): string =>
                            sprintf('La tâche "%s" est prévue demain (%s).', $taskName, $dueDate)
                    );
                }
            });
    }

    private function processOverdueTasks(Carbon $now, Carbon $windowStart): void
    {
        // Les notifications "task_overdue" partent uniquement le matin (09:00),
        // pas en pleine nuit, afin d'être cohérentes avec le comportement attendu.
        if (!$this->isWithinTimeWindow($now, $windowStart, '09:00')) {
            return;
        }

        TaskInstance::query()
            ->with(['template.household.users', 'user', 'assignees'])
            ->whereDate('due_date', '<', $now->toDateString())
            ->whereNull('completed_at')
            ->chunkById(100, function (Collection $instances): void {
                foreach ($instances as $instance) {
                    $household = $instance->template?->household;
                    $householdId = (int) ($household?->id ?? 0);

                    if ($householdId <= 0) {
                        continue;
                    }

                    $taskName = (string) ($instance->template?->name ?? 'Tâche');
                    $assignees = $this->resolveRecipientUsers($instance);

                    foreach ($assignees as $user) {
                        $alreadySent = UserNotification::query()
                            ->where('user_id', (int) $user->id)
                            ->where('household_id', $householdId)
                            ->where('type', 'task_overdue')
                            ->where('data->task_instance_id', (int) $instance->id)
                            ->exists();

                        if ($alreadySent) {
                            continue;
                        }

                        $title = 'Tâche en retard';
                        $body = sprintf('La tâche "%s" a dépassé son échéance.', $taskName);

                        $notification = UserNotification::query()->create([
                            'household_id' => $householdId,
                            'user_id' => (int) $user->id,
                            'type' => 'task_overdue',
                            'title' => $title,
                            'body' => $body,
                            'data' => [
                                'task_instance_id' => (int) $instance->id,
                                'task_template_id' => (int) ($instance->task_template_id ?? 0),
                                'task_name' => $taskName,
                                'due_date' => $instance->due_date?->toDateString(),
                            ],
                        ]);

                        $this->publishNotification(
                            (int) $user->id,
                            $householdId,
                            'task_overdue',
                            $title,
                            $body,
                            (int) $notification->id
                        );
                    }
                }
            });
    }

    private function notifyAssigneesOnce(
        TaskInstance $instance,
        string $type,
        string $title,
        callable $bodyResolver
    ): void {
        $household = $instance->template?->household;
        $householdId = (int) ($household?->id ?? 0);

        if ($householdId <= 0) {
            return;
        }

        $taskName = (string) ($instance->template?->name ?? 'Tâche');
        $dueDate = $instance->due_date?->format('d/m/Y') ?? '';
        $body = $bodyResolver($taskName, $dueDate);

        foreach ($this->resolveRecipientUsers($instance) as $user) {
            $alreadySent = UserNotification::query()
                ->where('user_id', (int) $user->id)
                ->where('household_id', $householdId)
                ->where('type', $type)
                ->where('data->task_instance_id', (int) $instance->id)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            $notification = UserNotification::query()->create([
                'household_id' => $householdId,
                'user_id' => (int) $user->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => [
                    'task_instance_id' => (int) $instance->id,
                    'task_template_id' => (int) ($instance->task_template_id ?? 0),
                    'task_name' => $taskName,
                    'due_date' => $instance->due_date?->toDateString(),
                ],
            ]);

            $this->publishNotification(
                (int) $user->id,
                $householdId,
                $type,
                $title,
                $body,
                (int) $notification->id
            );
        }
    }

    private function publishNotification(
        int $userId,
        int $householdId,
        string $type,
        string $title,
        string $body,
        int $notificationId
    ): void {
        $this->realtimePublisher->publishUser(
            userId: $userId,
            module: 'notifications',
            type: 'notification_created',
            payload: [
                'notification_id' => $notificationId,
                'household_id' => $householdId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
            ],
        );
    }

    private function isWithinTimeWindow(Carbon $now, Carbon $windowStart, string $targetTime): bool
    {
        [$hour, $minute] = explode(':', $targetTime);
        $target = $now->copy()->setTime((int) $hour, (int) $minute, 0);

        return !$target->lt($windowStart) && !$target->gt($now);
    }

    private function resolveRecurrence(TaskInstance $instance): ?string
    {
        $raw = $instance->template?->recurrence;

        if (!is_string($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));

        return in_array($value, ['daily', 'weekly', 'monthly', 'once'], true)
            ? $value
            : null;
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipientUsers(TaskInstance $instance): Collection
    {
        $assignees = $instance->assignees
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();

        if ($assignees->isNotEmpty()) {
            return $assignees;
        }

        if ($instance->user instanceof User) {
            return collect([$instance->user]);
        }

        return collect();
    }
}

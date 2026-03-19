<?php

namespace App\Listeners\Notifications;

use App\Events\Budget\AdvanceRequestedEvent;
use App\Models\Household;
use App\Models\User;
use App\Services\NotificationService;

class CreateAdvanceNotificationListener
{
    private const REQUEST_KIND_REIMBURSEMENT = 'reimbursement';

    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function handle(AdvanceRequestedEvent $event): void
    {
        $isReimbursement = $event->requestKind === self::REQUEST_KIND_REIMBURSEMENT;

        $this->notificationService->notifyUsers(
            $this->resolveParentUserIds($event->householdId),
            $event->householdId,
            $isReimbursement ? 'budget_reimbursement_requested' : 'budget_advance_requested',
            $isReimbursement ? 'Nouvelle demande de remboursement' : 'Nouvelle demande d\'avance',
            $isReimbursement
                ? sprintf('%s demande un remboursement de %0.2f €.', $event->requesterName, abs($event->amount))
                : sprintf('%s a demandé une avance de %0.2f €.', $event->requesterName, abs($event->amount)),
            [
                'transaction_id' => (int) $event->transaction->id,
                'user_id' => $event->requesterUserId,
                'amount' => abs($event->amount),
                'status' => (string) $event->transaction->status,
                'request_kind' => (string) $event->requestKind,
                'justification' => $this->normalizeNotificationJustification($event->comment),
            ],
        );
    }

    /**
     * @return array<int, int>
     */
    private function resolveParentUserIds(int $householdId): array
    {
        $household = Household::query()->find($householdId);
        if (!$household) {
            return [];
        }

        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    private function normalizeNotificationJustification(?string $comment): ?string
    {
        $value = trim((string) $comment);

        return $value === '' ? null : $value;
    }
}

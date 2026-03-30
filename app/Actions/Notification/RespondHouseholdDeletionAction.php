<?php

namespace App\Actions\Notification;

use App\Models\User;
use App\Models\UserNotification;
use App\Services\HouseholdDeletionService;
use Illuminate\Validation\ValidationException;

class RespondHouseholdDeletionAction
{
    public function __construct(private readonly HouseholdDeletionService $householdDeletionService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(UserNotification $notification, User $user, string $action): array
    {
        $type = (string) $notification->type;

        if ($type === HouseholdDeletionService::TYPE_APPROVAL_REQUEST) {
            if (!in_array($action, ['accept', 'refuse'], true)) {
                throw ValidationException::withMessages([
                    'action' => ['Action invalide pour cette demande.'],
                ]);
            }

            $result = $this->householdDeletionService->respondToApproval(
                $notification,
                $user,
                $action
            );

            return [
                'message' => $action === 'accept'
                    ? 'Demande de suppression acceptée.'
                    : 'Demande de suppression refusée.',
                'deletion_request' => $result,
            ];
        }

        if ($type === HouseholdDeletionService::TYPE_CANCEL_WINDOW) {
            if ($action !== 'cancel') {
                throw ValidationException::withMessages([
                    'action' => ['Action invalide pour cette demande.'],
                ]);
            }

            $result = $this->householdDeletionService->cancelScheduledDeletion(
                $notification,
                $user
            );

            return [
                'message' => 'Suppression planifiee annulee.',
                'deletion_request' => $result,
            ];
        }

        throw ValidationException::withMessages([
            'notification' => ['Cette notification ne concerne pas une suppression de foyer.'],
        ]);
    }
}


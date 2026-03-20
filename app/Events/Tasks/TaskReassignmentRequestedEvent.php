<?php

namespace App\Events\Tasks;

use App\Models\UserNotification;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskReassignmentRequestedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly UserNotification $invitationNotification,
        public readonly int $householdId,
        public readonly int $invitedUserId,
    ) {
    }
}

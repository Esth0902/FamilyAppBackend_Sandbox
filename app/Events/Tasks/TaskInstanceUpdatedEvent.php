<?php

namespace App\Events\Tasks;

use App\Models\TaskInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskInstanceUpdatedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, int>  $previousAssigneeIds
     * @param  array<int, int>  $currentAssigneeIds
     */
    public function __construct(
        public readonly TaskInstance $instance,
        public readonly int $householdId,
        public readonly string $householdName,
        public readonly int $actorUserId,
        public readonly string $actorName,
        public readonly string $previousStatus,
        public readonly bool $previousValidatedByParent,
        public readonly array $previousAssigneeIds,
        public readonly array $currentAssigneeIds,
    ) {
    }
}

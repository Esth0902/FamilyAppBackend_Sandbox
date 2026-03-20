<?php

namespace App\Events\Tasks;

use App\Models\TaskInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskInstanceCreatedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, int>  $assigneeIds
     * @param  array<int, int>  $instanceIds
     */
    public function __construct(
        public readonly TaskInstance $instance,
        public readonly int $householdId,
        public readonly int $actorUserId,
        public readonly string $actorName,
        public readonly array $assigneeIds,
        public readonly array $instanceIds,
    ) {
    }
}

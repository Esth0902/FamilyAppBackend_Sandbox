<?php

namespace App\Events\Tasks;

use App\Models\TaskInstance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskInstanceValidatedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<int, int>  $assigneeIds
     */
    public function __construct(
        public readonly TaskInstance $instance,
        public readonly int $householdId,
        public readonly int $validatedByUserId,
        public readonly string $validatedByName,
        public readonly array $assigneeIds,
    ) {
    }
}

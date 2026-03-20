<?php

namespace App\Events\Tasks;

use App\Models\TaskTemplate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskTemplateCreatedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly TaskTemplate $template,
        public readonly int $householdId,
        public readonly int $actorUserId,
        public readonly string $actorName,
    ) {
    }
}

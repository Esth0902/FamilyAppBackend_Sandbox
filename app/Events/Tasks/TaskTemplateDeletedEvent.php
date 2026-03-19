<?php

namespace App\Events\Tasks;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TaskTemplateDeletedEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $householdId,
        public readonly int $templateId,
        public readonly string $templateName,
    ) {
    }
}

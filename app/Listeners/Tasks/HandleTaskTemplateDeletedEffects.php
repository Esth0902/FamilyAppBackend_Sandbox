<?php

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskTemplateDeletedEvent;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleTaskTemplateDeletedEffects implements ShouldQueue
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function handle(TaskTemplateDeletedEvent $event): void
    {
        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'tasks',
            type: 'template.deleted',
            payload: [
                'template_id' => $event->templateId,
                'name' => $event->templateName,
                'household_id' => $event->householdId,
            ],
        );
    }
}

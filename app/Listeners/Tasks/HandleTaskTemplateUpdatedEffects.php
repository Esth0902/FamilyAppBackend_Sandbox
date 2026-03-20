<?php

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskTemplateUpdatedEvent;
use App\Services\RealtimePublisher;
use Illuminate\Contracts\Queue\ShouldQueue;

class HandleTaskTemplateUpdatedEffects implements ShouldQueue
{
    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function handle(TaskTemplateUpdatedEvent $event): void
    {
        $template = $event->template;

        $this->realtimePublisher->publishHousehold(
            householdId: $event->householdId,
            module: 'tasks',
            type: 'template.updated',
            payload: [
                'template_id' => (int) $template->id,
                'name' => (string) $template->name,
                'recurrence' => (string) $template->recurrence,
                'household_id' => $event->householdId,
            ],
        );
    }
}

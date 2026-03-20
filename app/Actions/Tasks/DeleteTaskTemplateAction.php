<?php

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskTemplateDeletedEvent;
use App\Models\Household;
use App\Models\TaskTemplate;

class DeleteTaskTemplateAction
{
    public function execute(Household $household, TaskTemplate $template): TaskTemplate
    {
        $templateId = (int) $template->id;
        $templateName = (string) $template->name;
        $template->delete();

        event(new TaskTemplateDeletedEvent(
            householdId: (int) $household->id,
            templateId: $templateId,
            templateName: $templateName,
        ));

        return $template;
    }
}

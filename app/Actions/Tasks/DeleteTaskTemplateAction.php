<?php

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskTemplateDeletedEvent;
use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Models\Household;
use App\Models\TaskTemplate;

class DeleteTaskTemplateAction
{
    use InteractsWithTaskContext;

    public function execute(Household $household, string $role, TaskTemplate $template): TaskTemplate
    {
        $this->ensureTasksModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTemplateBelongsToHousehold($template, $household);

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

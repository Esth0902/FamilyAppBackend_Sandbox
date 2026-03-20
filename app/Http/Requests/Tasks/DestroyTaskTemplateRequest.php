<?php

namespace App\Http\Requests\Tasks;

use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Requests\HouseholdAwareRequest;
use App\Models\TaskTemplate;

class DestroyTaskTemplateRequest extends HouseholdAwareRequest
{
    use InteractsWithTaskContext;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $template = $this->route('template');
        if (!$template instanceof TaskTemplate) {
            return false;
        }

        $this->ensureTasksModuleEnabled($this->household());
        $this->ensureParentRole($this->householdRole());
        $this->ensureTemplateBelongsToHousehold($template, $this->household());

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
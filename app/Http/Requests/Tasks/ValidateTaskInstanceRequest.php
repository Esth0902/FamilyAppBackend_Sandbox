<?php

namespace App\Http\Requests\Tasks;

use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Requests\HouseholdAwareRequest;
use App\Models\TaskInstance;

class ValidateTaskInstanceRequest extends HouseholdAwareRequest
{
    use InteractsWithTaskContext;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $instance = $this->route('instance');
        if (!$instance instanceof TaskInstance) {
            return false;
        }

        $this->ensureTasksModuleEnabled($this->household());
        $this->ensureParentRole($this->householdRole());
        $this->ensureInstanceBelongsToHousehold($instance, $this->household());

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
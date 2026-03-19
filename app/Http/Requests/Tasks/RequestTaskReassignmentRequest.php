<?php

namespace App\Http\Requests\Tasks;

use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Requests\HouseholdAwareRequest;
use App\Models\TaskInstance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Validator;

class RequestTaskReassignmentRequest extends HouseholdAwareRequest
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
        $this->ensureInstanceBelongsToHousehold($instance, $this->household());

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'invited_user_id' => ['required', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $instance = $this->route('instance');
            if (!$instance instanceof TaskInstance) {
                return;
            }

            if (!$this->isUserAssignedToInstance($instance, (int) $this->user()->id)) {
                throw new AuthorizationException('Seul un membre assigné peut demander une reprise.');
            }
        });
    }
}

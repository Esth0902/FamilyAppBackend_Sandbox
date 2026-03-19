<?php

namespace App\Http\Requests\Tasks;

use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Requests\HouseholdAwareRequest;
use App\Models\TaskInstance;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Validator;

class UpdateTaskInstanceRequest extends HouseholdAwareRequest
{
    use InteractsWithTaskContext;

    private const STATUS_TODO = "\u{00E0} faire";

    private const STATUS_DONE = "r\u{00E9}alis\u{00E9}e";

    private const STATUS_CANCELLED = "annul\u{00E9}e";

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

        if ($this->householdRole() === User::ROLE_PARENT) {
            return true;
        }

        if (!$this->isUserAssignedToInstance($instance, (int) $this->user()->id)) {
            throw new AuthorizationException('Vous pouvez modifier uniquement vos tâches.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', 'in:' . self::STATUS_TODO . ',' . self::STATUS_DONE . ',' . self::STATUS_CANCELLED],
            'due_date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'user_id' => ['sometimes', 'nullable', 'integer'],
            'user_ids' => ['sometimes', 'nullable', 'array'],
            'user_ids.*' => ['integer'],
            'validated_by_parent' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->householdRole() === User::ROLE_PARENT) {
                return;
            }

            $validated = $validator->safe()->all();
            if (
                array_key_exists('user_id', $validated)
                || array_key_exists('user_ids', $validated)
                || array_key_exists('due_date', $validated)
                || array_key_exists('validated_by_parent', $validated)
            ) {
                throw new AuthorizationException('Action réservée aux parents.');
            }

            if (
                array_key_exists('status', $validated)
                && !in_array((string) $validated['status'], [self::STATUS_TODO, self::STATUS_DONE], true)
            ) {
                throw new AuthorizationException('Statut non autorisé.');
            }
        });
    }
}

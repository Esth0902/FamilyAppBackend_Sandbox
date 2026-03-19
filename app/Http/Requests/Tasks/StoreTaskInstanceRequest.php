<?php

namespace App\Http\Requests\Tasks;

use App\Http\Controllers\Api\Concerns\InteractsWithTaskContext;
use App\Http\Requests\HouseholdAwareRequest;
use App\Models\User;
use App\Support\Normalization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Validator;

class StoreTaskInstanceRequest extends HouseholdAwareRequest
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

        $this->ensureTasksModuleEnabled($this->household());
        $this->ensureUserBelongsToHousehold((int) $this->user()->id, $this->household());

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'task_template_id' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:due_date'],
            'user_id' => ['nullable', 'integer'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer'],
            'status' => ['nullable', 'in:' . self::STATUS_TODO . ',' . self::STATUS_DONE . ',' . self::STATUS_CANCELLED],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->householdRole() === User::ROLE_PARENT) {
                return;
            }

            $validated = $validator->safe()->all();
            $currentUserId = (int) $this->user()->id;

            if (!empty($validated['user_id']) && (int) $validated['user_id'] !== $currentUserId) {
                throw new AuthorizationException('Un enfant peut uniquement s attribuer ses tâches.');
            }

            $requestedIds = Normalization::memberIds($validated['user_ids'] ?? null);
            if (count($requestedIds) > 0 && $requestedIds !== [$currentUserId]) {
                throw new AuthorizationException('Un enfant peut uniquement s attribuer ses tâches.');
            }
        });
    }
}

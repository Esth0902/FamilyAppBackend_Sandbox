<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\HouseholdAwareRequest;

class UpdateBudgetSettingRequest extends HouseholdAwareRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'base_amount' => ['required', 'numeric', 'min:0'],
            'recurrence' => ['required', 'in:weekly,monthly'],
            'reset_day' => ['required', 'integer', 'min:1', 'max:31'],
            'allow_advances' => ['required', 'boolean'],
            'max_advance_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}

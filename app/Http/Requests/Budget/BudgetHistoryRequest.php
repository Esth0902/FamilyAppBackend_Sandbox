<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\HouseholdAwareRequest;

class BudgetHistoryRequest extends HouseholdAwareRequest
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
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'user_id' => ['sometimes', 'integer'],
            'kind' => ['sometimes', 'string', 'in:payment,advance'],
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected'],
        ];
    }
}

<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\HouseholdAwareRequest;

class ValidatePaymentRequest extends HouseholdAwareRequest
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
            'user_id' => ['required', 'integer'],
            'action' => ['nullable', 'in:pay,carry_negative'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

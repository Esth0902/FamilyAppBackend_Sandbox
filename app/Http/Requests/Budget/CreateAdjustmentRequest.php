<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\HouseholdAwareRequest;

class CreateAdjustmentRequest extends HouseholdAwareRequest
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
            'type' => ['required', 'in:bonus,penalty'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

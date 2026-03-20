<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\HouseholdAwareRequest;

class UpdateAdjustmentRequest extends HouseholdAwareRequest
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
            'type' => ['sometimes', 'nullable', 'in:bonus,penalty'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}

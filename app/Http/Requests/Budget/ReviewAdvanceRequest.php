<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\HouseholdAwareRequest;

class ReviewAdvanceRequest extends HouseholdAwareRequest
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
            'status' => ['required', 'in:approved,rejected'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'payout_mode' => ['nullable', 'in:integrated,immediate'],
        ];
    }
}

<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\HouseholdAwareRequest;

class RequestAdvanceRequest extends HouseholdAwareRequest
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
            'amount' => ['required', 'numeric', 'gt:0'],
            'comment' => ['required', 'string', 'min:4', 'max:1000'],
        ];
    }
}


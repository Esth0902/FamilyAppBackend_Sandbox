<?php

namespace App\Http\Requests\Budget;

use App\Http\Requests\HouseholdAwareRequest;

class DeleteAdjustmentRequest extends HouseholdAwareRequest
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
        return [];
    }
}

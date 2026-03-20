<?php

namespace App\Http\Requests\MealPoll;

use App\Models\User;

class HistoryMealPollRequest extends MealPollContextRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}

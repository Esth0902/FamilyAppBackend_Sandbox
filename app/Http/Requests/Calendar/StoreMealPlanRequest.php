<?php

namespace App\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;

class StoreMealPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'meal_type' => ['required', 'string', 'in:matin,midi,soir'],
            'recipe_id' => ['nullable', 'integer', 'exists:recipes,id', 'required_without:custom_title'],
            'custom_title' => ['nullable', 'string', 'max:120', 'required_without:recipe_id'],
            'servings' => ['nullable', 'integer', 'min:1', 'max:30'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}

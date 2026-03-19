<?php

namespace App\Http\Resources\Budget;

use App\Models\BudgetSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BudgetSetting */
class BudgetSettingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'household_id' => (int) $this->household_id,
            'user_id' => (int) $this->user_id,
            'base_amount' => (float) $this->base_amount,
            'recurrence' => (string) $this->recurrence,
            'reset_day' => (int) $this->reset_day,
            'allow_advances' => (bool) $this->allow_advances,
            'max_advance_amount' => (float) $this->max_advance_amount,
        ];
    }
}

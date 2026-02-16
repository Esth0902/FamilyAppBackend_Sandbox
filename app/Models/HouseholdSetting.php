<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdSetting extends Model
{
    protected $fillable = [
        'household_id',
        'has_meals',
        'has_shopping_list',
        'has_tasks',
        'has_budget',
        'has_calendar',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}

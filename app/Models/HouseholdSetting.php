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
        'tasks_config',
        'calendar_config',
        'budget_config',
    ];

    protected $casts = [
        'has_meals' => 'boolean',
        'has_shopping_list' => 'boolean',
        'has_tasks' => 'boolean',
        'has_budget' => 'boolean',
        'has_calendar' => 'boolean',
        'tasks_config' => 'array',
        'calendar_config' => 'array',
        'budget_config' => 'array',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}

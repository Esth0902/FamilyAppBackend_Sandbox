<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPlan extends Model
{
    protected $fillable = [
        'household_id',
        'recipe_id',
        'date',
        'meal_type',
        'servings',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
        'servings' => 'integer',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MealPlanItem::class);
    }
}

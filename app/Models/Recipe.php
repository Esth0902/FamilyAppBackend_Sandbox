<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = [
        'household_id',
        'title',
        'type',
        'description',
        'instructions',
        'is_ai_generated',
        'source_url',
        'base_servings',
    ];

    protected $casts = [
        'is_ai_generated' => 'boolean',
        'base_servings' => 'integer',
    ];
    public function household()
    {
        return $this->belongsTo(Household::class);
    }

    public function ingredients()
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_recipe')
            ->withPivot('quantity', 'unit')
            ->withTimestamps();
    }

    public function mealPlans(): BelongsToMany
    {
        return $this->belongsToMany(MealPlan::class, 'meal_plan_items')
            ->withPivot('servings', 'position')
            ->withTimestamps();
    }

    public function lastTimeCooked()
    {
        return $this->mealPlans()->orderBy('date', 'desc')->first();
    }
}

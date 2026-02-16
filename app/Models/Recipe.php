<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $fillable = ['household_id', 'title', 'type', 'description', 'instructions', 'is_ai_generated', 'source_url'];
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

    public function mealPlans(): hasMany
    {
        return $this->hasMany(MealPlan::class);
    }

    public function lastTimeCooked()
    {
        return $this->mealPlans()->orderBy('date', 'desc')->first();
    }
}

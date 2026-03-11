<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Recipe extends Model
{
    protected $fillable = [
        'household_id',
        'is_global',
        'title',
        'type',
        'description',
        'instructions',
        'is_ai_generated',
        'source_url',
        'base_servings',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'is_ai_generated' => 'boolean',
        'base_servings' => 'integer',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'ingredient_recipe')
            ->withPivot('quantity', 'unit')
            ->withTimestamps();
    }

    public function savedByHouseholds(): BelongsToMany
    {
        return $this->belongsToMany(Household::class, 'household_recipe_bookmarks')
            ->withPivot('added_by_user_id')
            ->withTimestamps();
    }

    public function mealPlans(): BelongsToMany
    {
        return $this->belongsToMany(MealPlan::class, 'meal_plan_items')
            ->withPivot('servings', 'position')
            ->withTimestamps();
    }

    public function scopeVisibleForHousehold(Builder $query, int $householdId): Builder
    {
        return $query->where(function (Builder $visibleQuery) use ($householdId): void {
            $visibleQuery
                ->where('household_id', $householdId)
                ->orWhere('is_global', true);
        });
    }

    public function scopeMineForHousehold(Builder $query, int $householdId): Builder
    {
        return $query->where(function (Builder $mineQuery) use ($householdId): void {
            $mineQuery
                ->where('household_id', $householdId)
                ->orWhere(function (Builder $globalSavedQuery) use ($householdId): void {
                    $globalSavedQuery
                        ->where('is_global', true)
                        ->whereExists(function ($existsQuery) use ($householdId): void {
                            $existsQuery
                                ->selectRaw('1')
                                ->from('household_recipe_bookmarks')
                                ->whereColumn('household_recipe_bookmarks.recipe_id', 'recipes.id')
                                ->where('household_recipe_bookmarks.household_id', $householdId);
                        });
                });
        });
    }

    public function lastTimeCooked()
    {
        return $this->mealPlans()->orderBy('date', 'desc')->first();
    }
}

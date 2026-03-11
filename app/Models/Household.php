<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Household extends Model
{
    use HasFactory;
    protected $guarded = [];
    protected $casts = [
        'premium_ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'is_lifetime_premium' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role', 'nickname')
            ->withTimestamps();
    }
    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function savedRecipes(): BelongsToMany
    {
        return $this->belongsToMany(Recipe::class, 'household_recipe_bookmarks')
            ->withPivot('added_by_user_id')
            ->withTimestamps();
    }

    public function settings()
    {
        return $this->hasOne(HouseholdSetting::class);
    }

    public function taskTemplate()
    {
        return $this->hasMany(TaskTemplate::class);
    }

    public function budgetSettings()
    {
        return $this->hasMany(BudgetSetting::class);
    }

    public function pocketMoneyTransactions()
    {
        return $this->hasMany(PocketMoneyTransaction::class);
    }

    public function events() {
        return $this->hasMany(Event::class);
    }

    public function shoppingLists() {
        return $this->hasMany(ShoppingList::class);
    }


    public function dietaryTags()
    {
        return $this->belongsToMany(DietaryTag::class, 'household_dietary_tags');
    }

    public function mealSettings() {
        return $this->hasOne(MealSetting::class);
    }

    public function mealPlans()
    {
        return $this->hasMany(MealPlan::class);
    }

    public function mealPolls()
    {
        return $this->hasMany(MealPoll::class);
    }

    public function promoCodes()
    {
        return $this->belongsToMany(PromoCode::class, 'household_promo_code')
            ->withPivot('redeemed_at')
            ->withTimestamps();
    }

    public function getDaysLeftAttribute(): int
    {
        if ($this->is_lifetime_premium) return 9999;

        $end = $this->premium_ends_at ?? $this->trial_ends_at;

        if (!$end || $end->isPast()) return 0;

        return now()->diffInDays($end);
    }

    public function getIsPremiumAttribute(): bool
    {
        if ($this->is_lifetime_premium) {
            return true;
        }

        if ($this->premium_ends_at && $this->premium_ends_at->isFuture()) {
            return true;
        }
        if ($this->trial_ends_at && $this->trial_ends_at->isFuture()) {
            return true;
        }

        return false;
    }

}

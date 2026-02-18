<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealSetting extends Model
{
    protected $fillable = [
        'household_id',
        'poll_day',
        'poll_time',
        'poll_duration',
        'enable_recipes',
        'enable_polls',
        'enable_shopping_list',
        'auto_generate_shopping_list',
        'max_votes_per_user',
        'default_servings',
    ];

    protected $casts = [
        'poll_day' => 'integer',
        'poll_duration' => 'integer',
        'enable_recipes' => 'boolean',
        'enable_polls' => 'boolean',
        'enable_shopping_list' => 'boolean',
        'auto_generate_shopping_list' => 'boolean',
        'max_votes_per_user' => 'integer',
        'default_servings' => 'integer',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}

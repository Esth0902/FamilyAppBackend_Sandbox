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
        'auto_generate_shopping_list',
        'max_votes_per_user'
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}

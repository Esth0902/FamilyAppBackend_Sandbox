<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MealPollVote extends Model
{
    protected $fillable = ['meal_poll_id', 'user_id', 'meal_poll_option_id'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(MealPoll::class, 'meal_poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(MealPollOption::class, 'meal_poll_option_id');
    }
}

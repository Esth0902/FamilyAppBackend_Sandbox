<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealPollVote extends Model
{
    protected $fillable = ['meal_poll_id', 'user_id', 'meal_poll_option_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function option()
    {
        return $this->belongTo(MealPollOption::class, 'meal_poll_option_id');
    }
}

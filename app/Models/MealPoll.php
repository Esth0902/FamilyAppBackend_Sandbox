<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MealPoll extends Model
{
    protected $fillable = ['household_id', 'starts_at', 'ends_at', 'status'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function household()
    {
        return $this->belongsTo(Household::class);
    }

    public function options()
    {
        return $this->hasMany(MealPollOption::class);
    }

    public function votes()
    {
        return $this->hasMany(MealPollVote::class);
    }

}

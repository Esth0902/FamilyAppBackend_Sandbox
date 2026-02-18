<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPoll extends Model
{
    protected $fillable = [
        'household_id',
        'title',
        'created_by_user_id',
        'starts_at',
        'ends_at',
        'planning_start_date',
        'planning_end_date',
        'status',
        'max_votes_per_user',
        'closed_at',
        'validated_at',
        'validated_by_user_id',
        'reminder_sent_at',
        'closing_soon_sent_at',
        'close_request_sent_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'planning_start_date' => 'date',
        'planning_end_date' => 'date',
        'closed_at' => 'datetime',
        'validated_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'closing_soon_sent_at' => 'datetime',
        'close_request_sent_at' => 'datetime',
        'max_votes_per_user' => 'integer',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(MealPollOption::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(MealPollVote::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_user_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    protected $fillable = [
        'household_id',
        'name',
        'description',
        'recurrence',
        'start_date',
        'end_date',
        'recurrence_days',
        'assignee_user_ids',
        'rotation_user_ids',
        'is_rotation',
        'rotation_cycle_weeks',
        'is_inter_household_alternating',
        'inter_household_week_start',
        'fixed_user_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'recurrence_days' => 'array',
        'assignee_user_ids' => 'array',
        'rotation_user_ids' => 'array',
        'is_rotation' => 'boolean',
        'rotation_cycle_weeks' => 'integer',
        'is_inter_household_alternating' => 'boolean',
        'inter_household_week_start' => 'date',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function instances(): HasMany
    {
        return $this->hasMany(TaskInstance::class);
    }

    public function fixedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fixed_user_id');
    }
}

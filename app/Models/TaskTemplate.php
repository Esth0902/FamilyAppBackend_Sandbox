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
        'recurrence_days',
        'is_rotation',
        'rotation_cycle_weeks',
        'fixed_user_id',
    ];

    protected $casts = [
        'recurrence_days' => 'array',
        'is_rotation' => 'boolean',
        'rotation_cycle_weeks' => 'integer',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskInstance extends Model
{
    protected $fillable = [
        'task_template_id',
        'user_id',
        'due_date',
        'status',
        'completed_at',
        'validated_by_parent',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'validated_by_parent' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_instance_assignments', 'task_instance_id', 'user_id')
            ->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskInstanceAssignment::class, 'task_instance_id');
    }
}

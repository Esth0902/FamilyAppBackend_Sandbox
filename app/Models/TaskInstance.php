<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskInstanceAssignment extends Model
{
    protected $fillable = [
        'task_instance_id',
        'user_id',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(TaskInstance::class, 'task_instance_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

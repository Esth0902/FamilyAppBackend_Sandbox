<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskInstance extends Model
{
    protected $fillable = ['task_template_id', 'user_id', 'due_date', 'status', 'completed_at', 'validated_by_parent'];

    public function template() {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }
}

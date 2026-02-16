<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskTemplate extends Model
{

    protected $fillable = ['household_id', 'name', 'description', 'recurrence', 'is_rotation', 'fixed_user_id'];

    public function household() {
        return $this->belongsTo(Household::class);
    }

    public function instances()
    {
        return $this->hasMany(TaskInstance::class);
    }

    public function fixedUser()
    {
        return $this->belongsTo(User::class, 'fixed_user_id');
    }
}



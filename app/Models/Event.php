<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'household_id',
        'created_by_user_id',
        'title',
        'description',
        'start_at',
        'end_at',
        'is_shared_with_other_household',
        'lock_user_id',
        'lock_expires_at'
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'lock_expires_at' => 'datetime',
    ];

    public function isLockedByOthers($userId) : bool
    {
        if (!$this->lock_user_id || $this->lock_user_id == $userId) {
            return false;
        }

        return $this->lock_expires_at && $this->lock_expires_at->isFuture();
    }

    public function creator() {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function household() {
        return $this->belongsTo(Household::class);
    }
}

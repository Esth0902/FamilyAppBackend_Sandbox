<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    protected $fillable = [
        'household_id',
        'user_id',
        'type',
        'title',
        'body',
        'data',
        'scheduled_for',
        'sent_at',
        'read_at',
    ];

    protected $casts = [
        'data' => 'array',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


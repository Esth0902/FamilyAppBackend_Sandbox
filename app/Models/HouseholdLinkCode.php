<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdLinkCode extends Model
{
    protected $fillable = [
        'household_id',
        'created_by_user_id',
        'code',
        'expires_at',
        'used_at',
        'used_by_household_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function usedByHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'used_by_household_id');
    }
}

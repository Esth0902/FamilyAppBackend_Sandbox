<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HouseholdLinkRequest extends Model
{
    protected $fillable = [
        'from_household_id',
        'to_household_id',
        'requested_by_user_id',
        'responded_by_user_id',
        'household_link_code_id',
        'status',
        'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function fromHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'from_household_id');
    }

    public function toHousehold(): BelongsTo
    {
        return $this->belongsTo(Household::class, 'to_household_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function respondedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }

    public function linkCode(): BelongsTo
    {
        return $this->belongsTo(HouseholdLinkCode::class, 'household_link_code_id');
    }
}

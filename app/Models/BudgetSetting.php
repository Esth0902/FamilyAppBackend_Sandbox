<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetSetting extends Model
{
    protected $fillable = [
        'household_id',
        'user_id',
        'base_amount',
        'recurrence',
        'reset_day',
        'allow_advances',
        'max_advance_amount',
    ];

    protected $casts = [
        'base_amount' => 'float',
        'reset_day' => 'integer',
        'allow_advances' => 'boolean',
        'max_advance_amount' => 'float',
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

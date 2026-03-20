<?php

namespace App\Models;

use App\Casts\BudgetCommentCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PocketMoneyTransaction extends Model
{
    protected $fillable = [
        'household_id',
        'user_id',
        'amount',
        'type',
        'status',
        'comment',
    ];

    protected $casts = [
        'amount' => 'float',
        'comment' => BudgetCommentCast::class,
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

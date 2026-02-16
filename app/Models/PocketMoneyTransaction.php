<?php

namespace App\Models;

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

    public function household(): belongsTo
    {
        return $this -> belongsTo(Household::class);
    }

    public function user(): belongsTo
    {
        return $this -> belongsTo(User::class);
    }
}

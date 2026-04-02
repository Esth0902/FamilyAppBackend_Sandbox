<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $fillable = [
        'household_id',
        'user_id',
        'external_id',
        'provider',
        'model',
        'request_type',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'cost_usd',
        'latency_ms',
        'is_error',
        'error_message',
    ];

    protected $casts = [
        'cost_usd' => 'decimal:10',
        'is_error' => 'boolean',
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

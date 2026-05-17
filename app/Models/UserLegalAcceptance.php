<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLegalAcceptance extends Model
{
    use HasFactory;

    public const DOCUMENT_CGU = 'cgu';
    public const DOCUMENT_PRIVACY_POLICY = 'privacy_policy';

    protected $fillable = [
        'user_id',
        'document_type',
        'document_version',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


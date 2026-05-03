<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    public const AUDIENCE_ALL_MEMBERS = 'all_members';
    public const AUDIENCE_ONLY_ME = 'only_me';
    public const AUDIENCE_SELECTED_MEMBERS = 'selected_members';

    protected $fillable = [
        'household_id',
        'created_by_user_id',
        'title',
        'description',
        'start_at',
        'end_at',
        'is_shared_with_other_household',
        'audience_mode',
        'response_required',
        'lock_user_id',
        'lock_expires_at'
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'response_required' => 'boolean',
        'lock_expires_at' => 'datetime',
    ];

    public function isLockedByOthers($userId) : bool
    {
        if (!$this->lock_user_id || $this->lock_user_id == $userId) {
            return false;
        }

        return $this->lock_expires_at && $this->lock_expires_at->isFuture();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(EventParticipation::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(EventInvitation::class, 'event_id');
    }

    public static function normalizeAudienceMode(?string $mode): string
    {
        return in_array((string) $mode, [
            self::AUDIENCE_ALL_MEMBERS,
            self::AUDIENCE_ONLY_ME,
            self::AUDIENCE_SELECTED_MEMBERS,
        ], true)
            ? (string) $mode
            : self::AUDIENCE_ALL_MEMBERS;
    }
}

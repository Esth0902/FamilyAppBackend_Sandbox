<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */

    public const ROLE_PARENT = 'parent';
    public const ROLE_CHILD = 'enfant';
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'must_change_password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function households()
    {
        return $this->belongsToMany(Household::class)
            ->withPivot('role', 'nickname')
            ->withTimestamps();
    }

    public function taskInstances()
    {
        return $this->hasMany(TaskInstance::class);
    }

    public function budgetSettings()
    {
        return $this->hasOne(BudgetSetting::class);
    }

    public function pocketMoneyTransactions()
    {
        return $this->hasMany(PocketMoneyTransaction::class);
    }

    public function getPocketMoneyTransaction()
    {
        return $this->pocketMoneyTransactions()
            ->where('status', 'approved')
            ->sum('amount');
    }

    public function createdEvents() {
        return $this->hasMany(Event::class, 'created_by_user_id');
    }

    public function mealPollVotes()
    {
        return $this->hasMany(MealPollVote::class);
    }

    public function appNotifications()
    {
        return $this->hasMany(UserNotification::class);
    }
}

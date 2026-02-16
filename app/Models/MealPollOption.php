<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MealPollOption extends Model
{
    protected $fillable = [
        'meal_poll_id',
        'recipe_id'
    ];

    /**
     * L'option appartient à un sondage spécifique.
     */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(MealPoll::class, 'meal_poll_id');
    }

    /**
     * L'option est liée à une recette précise.
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * Récupère tous les votes enregistrés pour cette option précise.
     */
    public function votes(): hasMany
    {
        return $this->hasMany(MealPollVote::class, 'meal_poll_option_id');
    }

    /**
     * Compte le nombre de votes pour cette option.
     */
    public function getVotesCountAttribute(): int
    {
        return $this->votes()->count();
    }
}

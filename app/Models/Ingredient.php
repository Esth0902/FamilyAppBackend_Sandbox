<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ingredient extends Model
{
    protected $fillable = ['name', 'category', 'embedding'];

    public function recipes()
    {
        return $this->belongsToMany(Recipe::class, 'ingredient_recipe')
            ->withPivot('quantity', 'unit')
            ->withTimestamps();
    }
}

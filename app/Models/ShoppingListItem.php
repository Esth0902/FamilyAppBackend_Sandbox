<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingListItem extends Model
{
    protected $fillable = [
        'shopping_list_id',
        'ingredient_id',
        'name',
        'quantity',
        'unit',
        'is_checked',
        'is_manual_addition'
    ];

    protected $casts = [
        'is_checked' => 'boolean',
        'is_manual_addition' => 'boolean',
    ];

    public function shoppingList() {
        return $this->belongsTo(ShoppingList::class);
    }

    public function ingredient() {
        return $this->belongsTo(Ingredient::class);
    }

}

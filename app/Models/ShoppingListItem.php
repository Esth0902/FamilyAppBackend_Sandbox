<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class ShoppingListItem extends Model
{
    protected $fillable = [
        'shopping_list_id',
        'ingredient_id',
        'name',
        'quantity',
        'unit',
        'is_checked',
        'checked_by_user_id',
        'is_manual_addition',
        'created_by_user_id',
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

    public function checkedBy()
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

}

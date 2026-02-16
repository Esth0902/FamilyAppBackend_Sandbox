<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShoppingList extends Model
{
    protected $fillable = [
        'household_id',
        'title',
        'status'
    ];



    public function shoppingListItems() {
        return $this->hasMany(ShoppingListItem::class);
    }

    public function household() {
        return $this->belongsTo(Household::class);
    }

}

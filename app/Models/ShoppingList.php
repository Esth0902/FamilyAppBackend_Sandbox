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



    public function items()
    {
        return $this->hasMany(ShoppingListItem::class);
    }

    // Alias garde pour compatibilite avec l'ancien code.
    public function shoppingListItems()
    {
        return $this->items();
    }

    public function household() {
        return $this->belongsTo(Household::class);
    }

}

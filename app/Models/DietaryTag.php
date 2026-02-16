<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DietaryTag extends Model
{
    protected $fillable = [
        'type',
        'key',
        'label',
        'is_system',
        'created_by_household_id',
        'embedding',
    ];
    protected $casts = [
        'is_system' => 'boolean',
    ];
    public function creatorHousehold()
    {
        return $this->belongsTo(Household::class, 'created_by_household_id');
    }

    public function households()
    {
        return $this->belongsToMany(Household::class, 'household_dietary_tags');
    }
}

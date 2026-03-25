<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('household.{householdId}', function ($user, $householdId) {
    return $user->households()->where('household_id', $householdId)->exists();
});

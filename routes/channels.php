<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('household.{householdId}', function (User $user, int $householdId): bool {
    return $user->households()
        ->where('households.id', $householdId)
        ->exists();
});

<?php

namespace App\Actions\Auth;

use App\Models\User;

class LogoutUserAction
{
    public function execute(?User $user): void
    {
        $user?->currentAccessToken()?->delete();
    }
}

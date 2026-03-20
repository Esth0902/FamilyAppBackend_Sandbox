<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class VerifyUserPasswordAction
{
    public function execute(User $user, string $currentPassword): bool
    {
        return Hash::check($currentPassword, (string) $user->password);
    }
}

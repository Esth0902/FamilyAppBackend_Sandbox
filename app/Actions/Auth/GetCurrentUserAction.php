<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GetCurrentUserAction
{
    public function execute(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User) {
            throw ValidationException::withMessages([
                'user' => ['Utilisateur authentifie introuvable.'],
            ]);
        }

        return $user->load('households');
    }
}

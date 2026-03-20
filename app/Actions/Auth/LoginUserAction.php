<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginUserAction
{
    /**
     * @return array{user:User,token:string}
     */
    public function execute(string $normalizedEmail, string $password, bool $revokeExistingTokens = true): array
    {
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->with('households')
            ->first();

        if (!$user instanceof User || !Hash::check($password, (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants sont incorrects.'],
            ]);
        }

        if ($revokeExistingTokens) {
            $user->tokens()->delete();
        }

        return [
            'user' => $user,
            'token' => $user->createToken('mobile')->plainTextToken,
        ];
    }
}

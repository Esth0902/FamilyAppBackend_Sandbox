<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ChangeInitialCredentialsAction
{
    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function execute(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'email.unique' => "Cet e-mail est déjà utilisé.",
        ]);

        $user->forceFill([
            'email' => $validated['email'],
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        return [
            'status' => 200,
            'payload' => [
                'message' => 'Identifiants mis a jour.',
                'user' => $user->fresh()->load('households'),
            ],
        ];
    }

    private function normalizeEmailInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        return $normalized !== '' ? $normalized : null;
    }
}

<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UpdateProfileAction
{
    public function __construct(private readonly VerifyUserPasswordAction $verifyUserPasswordAction)
    {
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function execute(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        if ($request->exists('email')) {
            $request->merge([
                'email' => $this->normalizeEmailInput($request->input('email')),
            ]);
        }

        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'current_password' => ['required_with:email,password', 'string'],
        ], [
            'email.unique' => "Cet e-mail est déjà utilisé.",
        ]);

        $emailWasProvided = array_key_exists('email', $validated) && $validated['email'] !== null;
        $passwordWasProvided = array_key_exists('password', $validated);

        if (!$emailWasProvided && !$passwordWasProvided) {
            throw ValidationException::withMessages([
                'profile' => ['Aucune modification demandee.'],
            ]);
        }

        if (!$this->verifyUserPasswordAction->execute($user, (string) $validated['current_password'])) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $updates = [];
        if ($emailWasProvided) {
            $updates['email'] = (string) $validated['email'];
        }
        if ($passwordWasProvided) {
            $updates['password'] = (string) $validated['password'];
        }

        if (!empty($updates)) {
            $user->forceFill($updates)->save();
        }

        return [
            'status' => 200,
            'payload' => [
                'message' => 'Profil mis a jour.',
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

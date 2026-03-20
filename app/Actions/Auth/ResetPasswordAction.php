<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ResetPasswordAction
{
    /**
     * @return array{message:string}
     */
    public function execute(Request $request): array
    {
        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
        ]);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $status = PasswordFacade::reset(
            [
                'token' => (string) $validated['token'],
                'email' => (string) $validated['email'],
                'password' => (string) $validated['password'],
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ],
            function (User $user) use ($validated): void {
                $user->forceFill([
                    'password' => (string) $validated['password'],
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== PasswordFacade::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return [
            'message' => 'Mot de passe réinitialisé avec succès.',
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

<?php

namespace App\Actions\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password as PasswordFacade;

class ForgotPasswordAction
{
    /**
     * @return array{message:string}
     */
    public function execute(Request $request): array
    {
        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
        ]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        PasswordFacade::sendResetLink($request->only('email'));

        return [
            'message' => 'Si un compte existe pour cet e-mail, un lien de réinitialisation a été envoyé.',
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

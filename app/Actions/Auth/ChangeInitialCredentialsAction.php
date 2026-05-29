<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\LegalAcceptanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ChangeInitialCredentialsAction
{
    public function __construct(
        private readonly LegalAcceptanceService $legalAcceptanceService
    ) {
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function execute(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
            'cgu_version' => $this->normalizeVersionInput($request->input('cgu_version')),
            'privacy_policy_version' => $this->normalizeVersionInput($request->input('privacy_policy_version')),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_legal_terms' => ['accepted'],
            'cgu_version' => ['required', 'string', 'max:50'],
            'privacy_policy_version' => ['nullable', 'string', 'max:50'],
        ], [
            'email.unique' => "Cet e-mail est déjà utilisé.",
            'accept_legal_terms.accepted' => "L'acceptation des conditions est obligatoire.",
        ]);

        $user->forceFill([
            'email' => $validated['email'],
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        $this->legalAcceptanceService->recordAcceptances(
            $user,
            (string) $validated['cgu_version'],
            isset($validated['privacy_policy_version']) ? (string) $validated['privacy_policy_version'] : null
        );

        return [
            'status' => 200,
            'payload' => [
                'message' => 'Identifiants mis à jour.',
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

    private function normalizeVersionInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized !== '' ? $normalized : null;
    }
}

<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\LegalAcceptanceService;
use App\Services\LegalDocumentVersionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AcceptLegalDocumentsAction
{
    public function __construct(
        private readonly LegalAcceptanceService $legalAcceptanceService,
        private readonly LegalDocumentVersionService $legalDocumentVersionService
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
            'cgu_version' => $this->normalizeVersionInput($request->input('cgu_version')),
            'privacy_policy_version' => $this->normalizeVersionInput($request->input('privacy_policy_version')),
        ]);

        $validated = $request->validate([
            'accept_legal_terms' => ['accepted'],
            'cgu_version' => ['required', 'string', 'max:50'],
            'privacy_policy_version' => ['nullable', 'string', 'max:50'],
        ], [
            'accept_legal_terms.accepted' => "L'acceptation des conditions est obligatoire.",
        ]);

        $latestVersions = $this->legalDocumentVersionService->latestVersions();
        $resolvedPrivacyVersion = isset($validated['privacy_policy_version'])
            ? (string) $validated['privacy_policy_version']
            : (string) $validated['cgu_version'];

        if (
            (string) $validated['cgu_version'] !== $latestVersions['cgu']
            || $resolvedPrivacyVersion !== $latestVersions['privacy_policy']
        ) {
            throw ValidationException::withMessages([
                'cgu_version' => 'La version fournie ne correspond plus à la version en vigueur.',
            ]);
        }

        $this->legalAcceptanceService->recordAcceptances(
            $user,
            (string) $validated['cgu_version'],
            $resolvedPrivacyVersion
        );

        return [
            'status' => 200,
            'payload' => [
                'message' => 'Conditions mises à jour avec succès.',
                'user' => $user->fresh()->load('households'),
            ],
        ];
    }

    private function normalizeVersionInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized !== '' ? $normalized : null;
    }
}


<?php

namespace App\Http\Middleware;

use App\Services\LegalDocumentVersionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLegalAcceptanceUpToDate
{
    public function __construct(
        private readonly LegalDocumentVersionService $legalDocumentVersionService
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($this->isBypassedPath($request)) {
            return $next($request);
        }

        $latestVersions = $this->legalDocumentVersionService->latestVersions();
        $acceptedCguVersion = trim((string) ($user->accepted_cgu_version ?? ''));
        $acceptedPrivacyPolicyVersion = trim((string) ($user->accepted_privacy_policy_version ?? ''));

        $isUpToDate = $acceptedCguVersion === $latestVersions['cgu']
            && $acceptedPrivacyPolicyVersion === $latestVersions['privacy_policy'];

        if ($isUpToDate) {
            return $next($request);
        }

        return response()->json([
            'error' => 'cgu_update_required',
            'message' => 'Une mise à jour des conditions est requise pour continuer.',
            'latest_version' => $latestVersions['cgu'],
            'latest_versions' => $latestVersions,
        ], 403);
    }

    private function isBypassedPath(Request $request): bool
    {
        $allowedPaths = [
            'api/logout',
            'api/auth/change-initial-credentials',
            'api/auth/accept-legal-documents',
        ];

        foreach ($allowedPaths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}


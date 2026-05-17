<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserLegalAcceptance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class LegalAcceptanceService
{
    public function recordAcceptances(
        User $user,
        string $cguVersion,
        ?string $privacyPolicyVersion = null,
        ?CarbonInterface $acceptedAt = null
    ): void {
        $timestamp = $acceptedAt ?? Carbon::now();
        $resolvedPrivacyVersion = $privacyPolicyVersion ?: $cguVersion;

        $user->forceFill([
            'accepted_cgu_version' => $cguVersion,
            'accepted_cgu_at' => $timestamp,
            'accepted_privacy_policy_version' => $resolvedPrivacyVersion,
            'accepted_privacy_policy_at' => $timestamp,
        ])->save();

        $this->recordSingleAcceptance(
            $user,
            UserLegalAcceptance::DOCUMENT_CGU,
            $cguVersion,
            $timestamp
        );

        $this->recordSingleAcceptance(
            $user,
            UserLegalAcceptance::DOCUMENT_PRIVACY_POLICY,
            $resolvedPrivacyVersion,
            $timestamp
        );
    }

    private function recordSingleAcceptance(
        User $user,
        string $documentType,
        string $documentVersion,
        CarbonInterface $acceptedAt
    ): void {
        UserLegalAcceptance::query()->firstOrCreate(
            [
                'user_id' => (int) $user->id,
                'document_type' => $documentType,
                'document_version' => $documentVersion,
            ],
            [
                'accepted_at' => $acceptedAt,
            ]
        );
    }
}

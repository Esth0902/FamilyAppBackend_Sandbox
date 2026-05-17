<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class LegalDocumentVersionService
{
    /**
     * @return array{cgu:string,privacy_policy:string}
     */
    public function latestVersions(): array
    {
        return [
            'cgu' => $this->latestCguVersion(),
            'privacy_policy' => $this->latestPrivacyPolicyVersion(),
        ];
    }

    public function latestCguVersion(): string
    {
        return (string) Cache::rememberForever(
            $this->cacheKey('latest_cgu_version'),
            fn (): string => (string) config('legal.cgu.version', '2026-05-17')
        );
    }

    public function latestPrivacyPolicyVersion(): string
    {
        return (string) Cache::rememberForever(
            $this->cacheKey('latest_privacy_policy_version'),
            fn (): string => (string) config('legal.privacy_policy.version', '2026-05-17')
        );
    }

    private function cacheKey(string $baseKey): string
    {
        $cacheBuster = (string) config('legal.cache_buster', 'v1');
        return "legal:{$baseKey}:{$cacheBuster}";
    }
}


<?php

namespace App\Domain\Budget\ValueObjects;

class BudgetComment
{
    public const REQUEST_KIND_ADVANCE = 'advance';
    public const REQUEST_KIND_REIMBURSEMENT = 'reimbursement';

    public const PAYOUT_MODE_INTEGRATED = 'integrated';
    public const PAYOUT_MODE_IMMEDIATE = 'immediate';

    public const META_PREFIX = '[budget-meta]';

    public readonly ?string $displayComment;
    public readonly string $requestKind;
    public readonly ?string $payoutMode;

    public function __construct(
        ?string $displayComment = null,
        string $requestKind = self::REQUEST_KIND_ADVANCE,
        ?string $payoutMode = null
    ) {
        $cleanComment = trim((string) $displayComment);
        $this->displayComment = $cleanComment === '' ? null : $cleanComment;
        $this->requestKind = self::normalizeRequestKind($requestKind);
        $this->payoutMode = self::normalizePayoutMode($payoutMode);
    }

    public static function fromStored(?string $stored): self
    {
        $raw = (string) $stored;
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return new self(null, self::REQUEST_KIND_ADVANCE, null);
        }

        $lines = preg_split('/\R/u', $raw) ?: [];
        $firstLine = trim((string) ($lines[0] ?? ''));
        if (!str_starts_with($firstLine, self::META_PREFIX)) {
            return new self($trimmed, self::REQUEST_KIND_ADVANCE, null);
        }

        $requestKind = self::REQUEST_KIND_ADVANCE;
        $payoutMode = null;
        $rawMeta = trim(substr($firstLine, strlen(self::META_PREFIX)));

        foreach (explode(';', $rawMeta) as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $chunk, 2));
            if ($key === 'request_kind') {
                $requestKind = self::normalizeRequestKind($value);
            }
            if ($key === 'payout_mode') {
                $payoutMode = self::normalizePayoutMode($value);
            }
        }

        $display = trim(implode("\n", array_slice($lines, 1)));

        return new self($display === '' ? null : $display, $requestKind, $payoutMode);
    }

    public function toStoredString(): ?string
    {
        $hasMeta = $this->requestKind !== self::REQUEST_KIND_ADVANCE || $this->payoutMode !== null;
        if (!$hasMeta) {
            return $this->displayComment;
        }

        $parts = ['request_kind=' . $this->requestKind];
        if ($this->payoutMode !== null) {
            $parts[] = 'payout_mode=' . $this->payoutMode;
        }

        $header = self::META_PREFIX . implode(';', $parts);

        return $this->displayComment === null ? $header : $header . "\n" . $this->displayComment;
    }

    public function __toString(): string
    {
        return $this->displayComment ?? '';
    }

    private static function normalizeRequestKind(string $requestKind): string
    {
        return in_array($requestKind, [self::REQUEST_KIND_ADVANCE, self::REQUEST_KIND_REIMBURSEMENT], true)
            ? $requestKind
            : self::REQUEST_KIND_ADVANCE;
    }

    private static function normalizePayoutMode(?string $payoutMode): ?string
    {
        if ($payoutMode === null) {
            return null;
        }

        return in_array($payoutMode, [self::PAYOUT_MODE_INTEGRATED, self::PAYOUT_MODE_IMMEDIATE], true)
            ? $payoutMode
            : null;
    }
}

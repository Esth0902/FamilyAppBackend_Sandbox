<?php

namespace App\Http\Resources\Budget;

use App\Models\PocketMoneyTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PocketMoneyTransaction */
class TransactionResource extends JsonResource
{
    private const TYPE_PENALTY = 'penalty';
    private const TYPE_ADVANCE = 'advance';
    private const REQUEST_KIND_ADVANCE = 'advance';
    private const REQUEST_KIND_REIMBURSEMENT = 'reimbursement';
    private const PAYOUT_MODE_INTEGRATED = 'integrated';
    private const PAYOUT_MODE_IMMEDIATE = 'immediate';
    private const COMMENT_META_PREFIX = '[budget-meta]';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $displayComment = $this->comment;
        $requestKind = null;
        $payoutMode = null;

        if ((string) $this->type === self::TYPE_ADVANCE) {
            $meta = $this->extractBudgetCommentMetadata($this->comment);
            $displayComment = $meta['display_comment'];
            $requestKind = $meta['request_kind'];
            $payoutMode = $meta['payout_mode'];
        }

        return [
            'id' => (int) $this->id,
            'household_id' => (int) $this->household_id,
            'user_id' => (int) $this->user_id,
            'amount' => abs((float) $this->amount),
            'signed_amount' => $this->signedTransactionAmount((string) $this->type, (string) $requestKind),
            'type' => (string) $this->type,
            'status' => (string) $this->status,
            'comment' => $displayComment,
            'request_kind' => $requestKind,
            'payout_mode' => $payoutMode,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'user' => [
                'id' => (int) ($this->user?->id ?? 0),
                'name' => (string) ($this->user?->name ?? ''),
            ],
        ];
    }

    private function signedTransactionAmount(string $type, string $requestKind): float
    {
        $amount = abs((float) $this->amount);
        if ($type === self::TYPE_PENALTY) {
            return -$amount;
        }

        if ($type === self::TYPE_ADVANCE && $requestKind === self::REQUEST_KIND_REIMBURSEMENT) {
            return 0.0;
        }

        return $amount;
    }

    /**
     * @return array{request_kind:string,payout_mode:string|null,display_comment:string|null}
     */
    private function extractBudgetCommentMetadata(?string $storedComment): array
    {
        $raw = (string) $storedComment;
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [
                'request_kind' => self::REQUEST_KIND_ADVANCE,
                'payout_mode' => null,
                'display_comment' => null,
            ];
        }

        $lines = preg_split('/\R/u', $raw) ?: [];
        $first = trim((string) ($lines[0] ?? ''));
        if (!str_starts_with($first, self::COMMENT_META_PREFIX)) {
            return [
                'request_kind' => self::REQUEST_KIND_ADVANCE,
                'payout_mode' => null,
                'display_comment' => $trimmed,
            ];
        }

        $requestKind = self::REQUEST_KIND_ADVANCE;
        $payoutMode = null;
        $metaRaw = trim(substr($first, strlen(self::COMMENT_META_PREFIX)));

        foreach (explode(';', $metaRaw) as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $chunk, 2));
            if ($key === 'request_kind' && in_array($value, [self::REQUEST_KIND_ADVANCE, self::REQUEST_KIND_REIMBURSEMENT], true)) {
                $requestKind = $value;
            }

            if ($key === 'payout_mode' && in_array($value, [self::PAYOUT_MODE_INTEGRATED, self::PAYOUT_MODE_IMMEDIATE], true)) {
                $payoutMode = $value;
            }
        }

        $display = trim(implode("\n", array_slice($lines, 1)));

        return [
            'request_kind' => $requestKind,
            'payout_mode' => $payoutMode,
            'display_comment' => $display === '' ? null : $display,
        ];
    }
}


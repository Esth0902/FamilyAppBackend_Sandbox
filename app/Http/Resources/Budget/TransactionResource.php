<?php

namespace App\Http\Resources\Budget;

use App\Domain\Budget\Services\BudgetCalculationService;
use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Models\PocketMoneyTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PocketMoneyTransaction */
class TransactionResource extends JsonResource
{
    public static $wrap = null;

    private const TYPE_ADVANCE = 'advance';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $budgetComment = $this->comment instanceof BudgetComment
            ? $this->comment
            : BudgetComment::fromStored((string) $this->comment);

        $requestKind = null;
        $payoutMode = null;
        if ((string) $this->type === self::TYPE_ADVANCE) {
            $requestKind = $budgetComment->requestKind;
            $payoutMode = $budgetComment->payoutMode;
        }

        $calculationService = app(BudgetCalculationService::class);

        return [
            'id' => (int) $this->id,
            'household_id' => (int) $this->household_id,
            'user_id' => (int) $this->user_id,
            'amount' => abs((float) $this->amount),
            'signed_amount' => $calculationService->signedTransactionAmount(
                amount: (float) $this->amount,
                type: (string) $this->type,
                requestKind: (string) ($requestKind ?? BudgetComment::REQUEST_KIND_ADVANCE),
            ),
            'type' => (string) $this->type,
            'status' => (string) $this->status,
            'comment' => $budgetComment->displayComment,
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
}

<?php

namespace App\Actions\Budget;

use App\DTOs\Budget\AdvanceRequestDTO;
use App\Domain\Budget\Services\BudgetCalculationService;
use App\Events\Budget\AdvanceRequestedEvent;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RequestAdvanceAction
{
    private const TYPE_ADVANCE = 'advance';
    private const STATUS_PENDING = 'pending';
    private const REQUEST_KIND_ADVANCE = 'advance';
    private const COMMENT_META_PREFIX = '[budget-meta]';

    public function __construct(private readonly BudgetCalculationService $budgetCalculationService)
    {
    }

    public function execute(Household $household, User $currentUser, AdvanceRequestDTO $dto): PocketMoneyTransaction
    {
        $setting = BudgetSetting::query()
            ->where('household_id', $household->id)
            ->where('user_id', $currentUser->id)
            ->first();

        if (!$setting) {
            abort(403, 'Aucun paramètre budget configuré pour cet enfant.');
        }

        if (!(bool) $setting->allow_advances) {
            abort(403, 'Les avances sont désactivées pour ce budget.');
        }

        $requestedAmount = $this->budgetCalculationService->normalizeRequestedAmount($dto->amount);
        $maxAdvanceAmount = (float) $setting->max_advance_amount;
        if (!$this->budgetCalculationService->isAdvanceAmountAllowed($requestedAmount, $maxAdvanceAmount)) {
            throw ValidationException::withMessages([
                'amount' => ["Le montant demandé dépasse la limite autorisée ({$maxAdvanceAmount})."],
            ]);
        }

        $transaction = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $currentUser->id,
            'amount' => $requestedAmount,
            'type' => self::TYPE_ADVANCE,
            'status' => self::STATUS_PENDING,
            'comment' => $this->composeStoredComment($dto->comment, self::REQUEST_KIND_ADVANCE, null),
        ]);
        $transaction->load('user:id,name');

        event(new AdvanceRequestedEvent(
            transaction: $transaction,
            householdId: (int) $household->id,
            requesterUserId: (int) $currentUser->id,
            requesterName: (string) ($currentUser->name ?? 'Un enfant'),
            amount: $requestedAmount,
            requestKind: self::REQUEST_KIND_ADVANCE,
            comment: $dto->comment,
        ));

        return $transaction;
    }

    private function composeStoredComment(?string $comment, string $requestKind, ?string $payoutMode): ?string
    {
        $clean = trim((string) $comment);
        $hasMeta = $requestKind !== self::REQUEST_KIND_ADVANCE || $payoutMode !== null;
        if (!$hasMeta) {
            return $clean === '' ? null : $clean;
        }

        $parts = ['request_kind=' . $requestKind];
        if ($payoutMode !== null) {
            $parts[] = 'payout_mode=' . $payoutMode;
        }

        $header = self::COMMENT_META_PREFIX . implode(';', $parts);

        return $clean === '' ? $header : $header . "\n" . $clean;
    }
}
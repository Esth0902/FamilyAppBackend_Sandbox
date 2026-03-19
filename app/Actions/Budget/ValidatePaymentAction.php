<?php

namespace App\Actions\Budget;

use App\Actions\Budget\Concerns\InteractsWithBudgetContext;
use App\Actions\Budget\Results\ValidatePaymentResult;
use App\Domain\Budget\Services\BudgetCalculationService;
use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Events\Budget\NegativeBalanceCarriedOverEvent;
use App\Events\Budget\PaymentValidatedEvent;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;
use Illuminate\Validation\ValidationException;

class ValidatePaymentAction
{
    use InteractsWithBudgetContext;

    private const TYPE_ALLOCATION = 'allocation';
    private const TYPE_BONUS = 'bonus';
    private const TYPE_PENALTY = 'penalty';
    private const TYPE_ADVANCE = 'advance';

    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';

    private const REQUEST_KIND_ADVANCE = 'advance';

    public function __construct(private readonly BudgetCalculationService $budgetCalculationService)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(Household $household, string $role, array $payload): ValidatePaymentResult
    {
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);

        $child = $this->ensureChildBelongsToHousehold($household, (int) $payload['user_id']);
        $setting = BudgetSetting::query()
            ->where('household_id', $household->id)
            ->where('user_id', $child->id)
            ->first();

        $action = (string) ($payload['action'] ?? 'pay');
        if ($action === 'carry_negative') {
            return $this->carryNegativeBalance($household, (int) $child->id, $setting);
        }

        return $this->validateRegularPayment($household, (int) $child->id, $setting, $payload);
    }

    private function carryNegativeBalance(Household $household, int $childUserId, ?BudgetSetting $setting): ValidatePaymentResult
    {
        [$remainingRaw, $periodStart, $periodEnd] = $this->computeCurrentPeriodRemainingRaw(
            householdId: (int) $household->id,
            childUserId: $childUserId,
            setting: $setting,
        );

        if ($remainingRaw >= 0) {
            throw ValidationException::withMessages([
                'action' => ['Aucun solde négatif à reporter pour cet enfant.'],
            ]);
        }

        $carryAmount = abs($remainingRaw);
        $nextPeriodStart = $periodEnd->copy()->addSecond()->startOfSecond();

        $existingCarry = PocketMoneyTransaction::query()
            ->where('household_id', $household->id)
            ->where('user_id', $childUserId)
            ->where('type', self::TYPE_ADVANCE)
            ->where('status', self::STATUS_APPROVED)
            ->where('comment', 'Report automatique du solde négatif de la période précédente.')
            ->where('created_at', $nextPeriodStart)
            ->first();

        if ($existingCarry) {
            return new ValidatePaymentResult(
                message: 'Ce montant négatif est déjà reporté au prochain budget.',
                carryAmount: $carryAmount,
                periodStart: $periodStart->toDateString(),
                periodEnd: $periodEnd->toDateString(),
                nextPeriodStart: $nextPeriodStart->toDateString(),
            );
        }

        $carryTransaction = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $childUserId,
            'amount' => $carryAmount,
            'type' => self::TYPE_ADVANCE,
            'status' => self::STATUS_APPROVED,
            'comment' => 'Report automatique du solde négatif de la période précédente.',
        ]);
        $carryTransaction->timestamps = false;
        $carryTransaction->created_at = $nextPeriodStart;
        $carryTransaction->updated_at = $nextPeriodStart;
        $carryTransaction->save();

        $resetCount = $this->resetCurrentPeriodAdjustmentsForChild((int) $household->id, $childUserId, $setting);

        event(new NegativeBalanceCarriedOverEvent(
            householdId: (int) $household->id,
            userId: $childUserId,
            carryAmount: $carryAmount,
            periodStart: $periodStart->toDateString(),
            periodEnd: $periodEnd->toDateString(),
            nextPeriodStart: $nextPeriodStart->toDateString(),
            resetAdjustmentsCount: $resetCount,
        ));

        return new ValidatePaymentResult(
            message: 'Montant négatif reporté au prochain budget.',
            carryAmount: $carryAmount,
            periodStart: $periodStart->toDateString(),
            periodEnd: $periodEnd->toDateString(),
            nextPeriodStart: $nextPeriodStart->toDateString(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateRegularPayment(Household $household, int $childUserId, ?BudgetSetting $setting, array $payload): ValidatePaymentResult
    {
        $defaultAmount = $setting ? (float) $setting->base_amount : 0.0;
        $finalAmount = array_key_exists('amount', $payload) && $payload['amount'] !== null
            ? (float) $payload['amount']
            : $defaultAmount;

        if ($finalAmount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Le montant validé doit être supérieur à 0.'],
            ]);
        }

        $transaction = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $childUserId,
            'amount' => abs($finalAmount),
            'type' => self::TYPE_ALLOCATION,
            'status' => self::STATUS_APPROVED,
            'comment' => isset($payload['comment']) && trim((string) $payload['comment']) !== ''
                ? trim((string) $payload['comment'])
                : 'Versement validé par le parent.',
        ]);
        $transaction->load('user:id,name');

        $resetCount = $this->resetCurrentPeriodAdjustmentsForChild((int) $household->id, $childUserId, $setting);

        event(new PaymentValidatedEvent(
            transaction: $transaction,
            householdId: (int) $household->id,
            userId: $childUserId,
            amount: abs((float) $transaction->amount),
            resetAdjustmentsCount: $resetCount,
        ));

        return new ValidatePaymentResult(
            message: 'Paiement validé.',
            statusCode: 201,
            transaction: $transaction,
        );
    }

    private function mergeTransactionComment(?string $existingComment, string $reviewComment): ?string
    {
        $base = trim((string) $existingComment);
        $review = trim($reviewComment);
        if ($review === '') {
            return $base === '' ? null : $base;
        }

        return $base === '' ? 'Note parent: ' . $review : $base . "\n\n" . 'Note parent: ' . $review;
    }

    private function resolveAdvanceRequestKind(PocketMoneyTransaction $transaction): string
    {
        if ((string) $transaction->type !== self::TYPE_ADVANCE) {
            return self::REQUEST_KIND_ADVANCE;
        }

        $comment = $transaction->comment instanceof BudgetComment
            ? $transaction->comment
            : BudgetComment::fromStored((string) $transaction->comment);

        return $comment->requestKind;
    }

    private function resolveDisplayComment(mixed $comment): ?string
    {
        if ($comment instanceof BudgetComment) {
            return $comment->displayComment;
        }

        $value = trim((string) $comment);

        return $value === '' ? null : $value;
    }

    private function resetCurrentPeriodAdjustmentsForChild(int $householdId, int $childUserId, ?BudgetSetting $setting): int
    {
        $recurrence = (string) ($setting?->recurrence ?? 'weekly');
        $resetDay = $this->budgetCalculationService->normalizeResetDay((int) ($setting?->reset_day ?? 1), $recurrence);
        [$periodStart, $periodEnd] = $this->budgetCalculationService->resolvePeriodBoundaries($recurrence, $resetDay, now());

        $adjustments = PocketMoneyTransaction::query()
            ->where('household_id', $householdId)
            ->where('user_id', $childUserId)
            ->whereIn('type', [self::TYPE_BONUS, self::TYPE_PENALTY])
            ->where('status', self::STATUS_APPROVED)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->get();

        foreach ($adjustments as $transaction) {
            $transaction->update([
                'status' => self::STATUS_REJECTED,
                'comment' => $this->mergeTransactionComment(
                    $this->resolveDisplayComment($transaction->comment),
                    'Ajustement appliqué et réinitialisé lors du paiement.'
                ),
            ]);
        }

        return $adjustments->count();
    }

    /**
     * @return array{0: float, 1: \Carbon\Carbon, 2: \Carbon\Carbon}
     */
    private function computeCurrentPeriodRemainingRaw(int $householdId, int $childUserId, ?BudgetSetting $setting): array
    {
        $recurrence = (string) ($setting?->recurrence ?? 'weekly');
        $resetDay = $this->budgetCalculationService->normalizeResetDay((int) ($setting?->reset_day ?? 1), $recurrence);
        [$periodStart, $periodEnd] = $this->budgetCalculationService->resolvePeriodBoundaries($recurrence, $resetDay, now());

        $transactions = PocketMoneyTransaction::query()
            ->where('household_id', $householdId)
            ->where('user_id', $childUserId)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->get();

        $approved = $transactions->where('status', self::STATUS_APPROVED)->values();
        $baseAmount = $setting ? (float) $setting->base_amount : 0.0;
        $bonusTotal = $approved
            ->where('type', self::TYPE_BONUS)
            ->sum(static fn (PocketMoneyTransaction $tx): float => abs((float) $tx->amount));
        $penaltyTotal = $approved
            ->where('type', self::TYPE_PENALTY)
            ->sum(static fn (PocketMoneyTransaction $tx): float => -abs((float) $tx->amount));
        $advanceToDeduct = $approved
            ->where('type', self::TYPE_ADVANCE)
            ->filter(fn (PocketMoneyTransaction $tx): bool => $this->resolveAdvanceRequestKind($tx) === self::REQUEST_KIND_ADVANCE)
            ->sum(static fn (PocketMoneyTransaction $tx): float => abs((float) $tx->amount));
        $alreadyPaid = $approved
            ->where('type', self::TYPE_ALLOCATION)
            ->sum(static fn (PocketMoneyTransaction $tx): float => abs((float) $tx->amount));

        $remainingRaw = $this->budgetCalculationService->calculateRemainingRaw(
            baseAmount: $baseAmount,
            bonusTotal: $bonusTotal,
            penaltyTotal: $penaltyTotal,
            advanceToDeduct: $advanceToDeduct,
            alreadyPaid: $alreadyPaid,
        );

        return [$remainingRaw, $periodStart, $periodEnd];
    }
}

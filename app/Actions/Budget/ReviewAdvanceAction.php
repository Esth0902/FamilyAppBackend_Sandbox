<?php

namespace App\Actions\Budget;

use App\Actions\Budget\Concerns\InteractsWithBudgetContext;
use App\Actions\Budget\Results\BudgetTransactionResult;
use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Events\Budget\AdvanceReviewedEvent;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewAdvanceAction
{
    use InteractsWithBudgetContext;

    private const TYPE_ADVANCE = 'advance';
    private const TYPE_ALLOCATION = 'allocation';
    private const TYPE_BONUS = 'bonus';

    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';

    private const REQUEST_KIND_ADVANCE = 'advance';
    private const REQUEST_KIND_REIMBURSEMENT = 'reimbursement';

    private const PAYOUT_MODE_INTEGRATED = 'integrated';
    private const PAYOUT_MODE_IMMEDIATE = 'immediate';

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(
        Household $household,
        string $role,
        PocketMoneyTransaction $transaction,
        array $payload
    ): BudgetTransactionResult {
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTransactionBelongsToHousehold($transaction, $household);

        return DB::transaction(function () use ($household, $transaction, $payload): BudgetTransactionResult {
            /** @var PocketMoneyTransaction $transaction */
            $transaction = PocketMoneyTransaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

        if ((string) $transaction->type !== self::TYPE_ADVANCE) {
            throw ValidationException::withMessages([
                'transaction' => ['Seules les demandes d\'avance et de remboursement peuvent être traitées ici.'],
            ]);
        }
        if ((string) $transaction->status !== self::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'transaction' => ['Cette demande a déjà été traitée.'],
            ]);
        }

        $budgetComment = $transaction->comment instanceof BudgetComment
            ? $transaction->comment
            : BudgetComment::fromStored((string) $transaction->comment);
        $requestKind = $budgetComment->requestKind;
        $isReimbursement = $requestKind === self::REQUEST_KIND_REIMBURSEMENT;

        $finalStatus = (string) $payload['status'];
        $finalAmount = $finalStatus === self::STATUS_APPROVED
            ? (array_key_exists('amount', $payload) && $payload['amount'] !== null
                ? abs((float) $payload['amount'])
                : abs((float) $transaction->amount))
            : abs((float) $transaction->amount);

        $payoutMode = null;
        if (!$isReimbursement && array_key_exists('payout_mode', $payload) && $payload['payout_mode'] !== null) {
            throw ValidationException::withMessages([
                'payout_mode' => ['Le mode de paiement est disponible uniquement pour les remboursements.'],
            ]);
        }

        if ($finalStatus === self::STATUS_APPROVED) {
            if ($isReimbursement) {
                $payoutMode = array_key_exists('payout_mode', $payload) && $payload['payout_mode'] !== null
                    ? (string) $payload['payout_mode']
                    : self::PAYOUT_MODE_INTEGRATED;

                PocketMoneyTransaction::query()->create([
                    'household_id' => $household->id,
                    'user_id' => (int) $transaction->user_id,
                    'amount' => abs($finalAmount),
                    'type' => self::TYPE_BONUS,
                    'status' => self::STATUS_APPROVED,
                    'comment' => $payoutMode === self::PAYOUT_MODE_IMMEDIATE
                        ? 'Remboursement validé et payé immédiatement.'
                        : 'Remboursement validé, intégré au prochain paiement.',
                ]);

                if ($payoutMode === self::PAYOUT_MODE_IMMEDIATE) {
                    PocketMoneyTransaction::query()->create([
                        'household_id' => $household->id,
                        'user_id' => (int) $transaction->user_id,
                        'amount' => abs($finalAmount),
                        'type' => self::TYPE_ALLOCATION,
                        'status' => self::STATUS_APPROVED,
                        'comment' => 'Paiement immédiat du remboursement validé.',
                    ]);
                }
            } else {
                $setting = BudgetSetting::query()
                    ->where('household_id', $household->id)
                    ->where('user_id', $transaction->user_id)
                    ->first();

                if (!$setting || !(bool) $setting->allow_advances) {
                    throw ValidationException::withMessages([
                        'status' => ['Les avances ne sont plus autorisées pour cet enfant.'],
                    ]);
                }

                $maxAdvanceAmount = (float) $setting->max_advance_amount;
                if ($maxAdvanceAmount <= 0 || $finalAmount > $maxAdvanceAmount) {
                    throw ValidationException::withMessages([
                        'amount' => ["Le montant approuvé dépasse la limite autorisée ({$maxAdvanceAmount})."],
                    ]);
                }
            }
        }

        $reviewComment = isset($payload['comment']) ? trim((string) $payload['comment']) : '';
        $mergedComment = $this->mergeTransactionComment($budgetComment->displayComment, $reviewComment);

        $transaction->update([
            'status' => $finalStatus,
            'amount' => $finalAmount,
            'comment' => new BudgetComment(
                displayComment: $mergedComment,
                requestKind: $requestKind,
                payoutMode: $finalStatus === self::STATUS_APPROVED ? $payoutMode : null,
            ),
        ]);
        $transaction->load('user:id,name');

        $approved = $finalStatus === self::STATUS_APPROVED;
        $modeText = $payoutMode === self::PAYOUT_MODE_IMMEDIATE
            ? ' et payée immédiatement'
            : ($payoutMode === self::PAYOUT_MODE_INTEGRATED ? ' et intégrée au paiement' : '');

        event(new AdvanceReviewedEvent(
            transaction: $transaction,
            householdId: (int) $household->id,
            userId: (int) $transaction->user_id,
            amount: abs((float) $transaction->amount),
            status: (string) $transaction->status,
            requestKind: $requestKind,
            payoutMode: $payoutMode,
            approved: $approved,
            isReimbursement: $isReimbursement,
            modeText: $modeText,
            justification: $this->normalizeNotificationJustification($mergedComment),
        ));

        return new BudgetTransactionResult(
            message: $approved
                ? ($isReimbursement ? 'Demande de remboursement approuvée.' : 'Demande d\'avance approuvée.')
                : ($isReimbursement ? 'Demande de remboursement refusée.' : 'Demande d\'avance refusée.'),
            transaction: $transaction,
        );
        });
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

    private function normalizeNotificationJustification(?string $comment): ?string
    {
        $value = trim((string) $comment);

        return $value === '' ? null : $value;
    }

}

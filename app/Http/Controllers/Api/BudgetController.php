<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Http\Controllers\Controller;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\RealtimePublisher;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BudgetController extends Controller
{
    use ResolvesHouseholdContext;

    private const TYPE_ALLOCATION = 'allocation';
    private const TYPE_BONUS = 'bonus';
    private const TYPE_PENALTY = 'penalty';
    private const TYPE_ADVANCE = 'advance';

    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';

    private const REQUEST_KIND_ADVANCE = 'advance';
    private const REQUEST_KIND_REIMBURSEMENT = 'reimbursement';
    private const PAYOUT_MODE_INTEGRATED = 'integrated';
    private const PAYOUT_MODE_IMMEDIATE = 'immediate';
    private const COMMENT_META_PREFIX = '[budget-meta]';

    public function __construct(private readonly RealtimePublisher $realtimePublisher)
    {
    }

    public function board(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);

        $currentUserId = (int) $request->user()->id;
        $isParent = (string) $role === User::ROLE_PARENT;

        $children = $household->users()
            ->select('users.id', 'users.name')
            ->wherePivot('role', User::ROLE_CHILD)
            ->orderBy('users.name')
            ->get();

        $targetChildren = $isParent ? $children : $children->where('id', $currentUserId)->values();
        $childIds = $targetChildren->pluck('id')->map(static fn(mixed $id): int => (int) $id)->values();

        $settingsByUserId = BudgetSetting::query()
            ->where('household_id', $household->id)
            ->whereIn('user_id', $childIds)
            ->get()
            ->keyBy('user_id');

        $transactionsByUserId = PocketMoneyTransaction::query()
            ->where('household_id', $household->id)
            ->whereIn('user_id', $childIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('user_id');

        $childrenPayload = $targetChildren->map(function (User $child) use ($settingsByUserId, $transactionsByUserId): array {
            /** @var BudgetSetting|null $setting */
            $setting = $settingsByUserId->get((int) $child->id);
            $transactions = $transactionsByUserId->get((int) $child->id, collect());
            return $this->toChildBudgetPayload($child, $setting, $transactions, now());
        })->values();

        $pendingRequests = [];
        if ($isParent) {
            $pendingRequests = PocketMoneyTransaction::query()
                ->where('household_id', $household->id)
                ->where('type', self::TYPE_ADVANCE)
                ->where('status', self::STATUS_PENDING)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn(PocketMoneyTransaction $tx): array => $this->toTransactionPayload($tx, true))
                ->values()
                ->all();
        }

        $budgetConfig = $this->resolveBudgetConfig($household);

        return $this->budgetJson([
            'budget_enabled' => true,
            'currency' => (string) ($budgetConfig['currency'] ?? 'EUR'),
            'settings' => $budgetConfig,
            'current_user' => ['id' => $currentUserId, 'role' => (string) $role],
            'children' => $childrenPayload,
            'pending_advance_requests' => $pendingRequests,
        ]);
    }

    public function updateSetting(Request $request, User $user): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureChildBelongsToHousehold($household, (int) $user->id);

        $validated = $request->validate([
            'base_amount' => ['required', 'numeric', 'min:0'],
            'recurrence' => ['required', 'in:weekly,monthly'],
            'reset_day' => ['required', 'integer', 'min:1', 'max:31'],
            'allow_advances' => ['required', 'boolean'],
            'max_advance_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $recurrence = (string) $validated['recurrence'];
        $resetDay = (int) $validated['reset_day'];
        if ($recurrence === 'weekly' && $resetDay > 7) {
            throw ValidationException::withMessages(['reset_day' => ['Le reset_day hebdomadaire doit être compris entre 1 et 7.']]);
        }

        $allowAdvances = (bool) $validated['allow_advances'];
        $maxAdvanceAmount = $allowAdvances ? (float) $validated['max_advance_amount'] : 0.0;
        if ($allowAdvances && $maxAdvanceAmount <= 0) {
            throw ValidationException::withMessages(['max_advance_amount' => ["Le montant maximum d'avance doit être supérieur à 0 si les avances sont activées."]]);
        }

        $setting = BudgetSetting::query()->updateOrCreate(
            ['household_id' => $household->id, 'user_id' => $user->id],
            [
                'base_amount' => (float) $validated['base_amount'],
                'recurrence' => $recurrence,
                'reset_day' => $resetDay,
                'allow_advances' => $allowAdvances,
                'max_advance_amount' => $maxAdvanceAmount,
            ]
        );

        $this->publishBudgetRealtime((int) $household->id, 'setting.updated', [
            'user_id' => (int) $user->id,
            'recurrence' => (string) $setting->recurrence,
            'reset_day' => (int) $setting->reset_day,
            'allow_advances' => (bool) $setting->allow_advances,
        ]);

        return $this->budgetJson([
            'message' => 'Paramètres du budget mis à jour.',
            'setting' => $this->toSettingPayload($setting),
        ]);
    }

    public function validatePayment(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'action' => ['nullable', 'in:pay,carry_negative'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $child = $this->ensureChildBelongsToHousehold($household, (int) $validated['user_id']);
        $setting = BudgetSetting::query()->where('household_id', $household->id)->where('user_id', $child->id)->first();
        $action = (string) ($validated['action'] ?? 'pay');

        if ($action === 'carry_negative') {
            [$remainingRaw, $periodStart, $periodEnd] = $this->computeCurrentPeriodRemainingRaw(
                householdId: (int) $household->id,
                childUserId: (int) $child->id,
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
                ->where('user_id', $child->id)
                ->where('type', self::TYPE_ADVANCE)
                ->where('status', self::STATUS_APPROVED)
                ->where('comment', 'Report automatique du solde négatif de la période précédente.')
                ->where('created_at', $nextPeriodStart)
                ->first();

            if ($existingCarry) {
                return $this->budgetJson([
                    'message' => 'Ce montant négatif est déjà reporté au prochain budget.',
                    'carry_amount' => $carryAmount,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'next_period_start' => $nextPeriodStart->toDateString(),
                ]);
            }

            $carryTransaction = PocketMoneyTransaction::query()->create([
                'household_id' => $household->id,
                'user_id' => $child->id,
                'amount' => $carryAmount,
                'type' => self::TYPE_ADVANCE,
                'status' => self::STATUS_APPROVED,
                'comment' => 'Report automatique du solde négatif de la période précédente.',
            ]);
            $carryTransaction->timestamps = false;
            $carryTransaction->created_at = $nextPeriodStart;
            $carryTransaction->updated_at = $nextPeriodStart;
            $carryTransaction->save();

            $resetCount = $this->resetCurrentPeriodAdjustmentsForChild((int) $household->id, (int) $child->id, $setting);

            $this->notifyUser(
                (int) $child->id,
                (int) $household->id,
                'budget_negative_carried_over',
                'Solde reporté',
                sprintf('Un solde de %0.2f € sera déduit du prochain budget.', $carryAmount),
                [
                    'user_id' => (int) $child->id,
                    'carry_amount' => $carryAmount,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'next_period_start' => $nextPeriodStart->toDateString(),
                    'reset_adjustments_count' => $resetCount,
                ],
            );

            $this->publishBudgetRealtime((int) $household->id, 'payment.negative_carried', [
                'user_id' => (int) $child->id,
                'carry_amount' => $carryAmount,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'next_period_start' => $nextPeriodStart->toDateString(),
                'reset_adjustments_count' => $resetCount,
            ]);

            return $this->budgetJson([
                'message' => 'Montant négatif reporté au prochain budget.',
                'carry_amount' => $carryAmount,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'next_period_start' => $nextPeriodStart->toDateString(),
            ]);
        }

        $defaultAmount = $setting ? (float) $setting->base_amount : 0.0;
        $finalAmount = array_key_exists('amount', $validated) && $validated['amount'] !== null ? (float) $validated['amount'] : $defaultAmount;
        if ($finalAmount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Le montant validé doit être supérieur à 0.']]);
        }

        $transaction = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'amount' => abs($finalAmount),
            'type' => self::TYPE_ALLOCATION,
            'status' => self::STATUS_APPROVED,
            'comment' => isset($validated['comment']) && trim((string) $validated['comment']) !== '' ? trim((string) $validated['comment']) : 'Versement validé par le parent.',
        ]);

        $resetCount = $this->resetCurrentPeriodAdjustmentsForChild((int) $household->id, (int) $child->id, $setting);
        $transaction->load('user:id,name');

        $this->notifyUser(
            (int) $child->id,
            (int) $household->id,
            'budget_payment_validated',
            'Paiement validé',
            sprintf('Ton paiement de %0.2f a été validé.', abs($finalAmount)),
            [
                'transaction_id' => (int) $transaction->id,
                'amount' => abs((float) $transaction->amount),
                'status' => (string) $transaction->status,
                'reset_adjustments_count' => $resetCount,
            ],
        );

        $this->publishBudgetRealtime((int) $household->id, 'payment.validated', [
            'transaction_id' => (int) $transaction->id,
            'user_id' => (int) $child->id,
            'amount' => abs((float) $transaction->amount),
            'reset_adjustments_count' => $resetCount,
        ]);

        return $this->budgetJson([
            'message' => 'Paiement validé.',
            'transaction' => $this->toTransactionPayload($transaction, true),
        ], 201);
    }

    public function createAdjustment(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'type' => ['required', 'in:' . self::TYPE_BONUS . ',' . self::TYPE_PENALTY],
            'amount' => ['required', 'numeric', 'gt:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $child = $this->ensureChildBelongsToHousehold($household, (int) $validated['user_id']);
        $type = (string) $validated['type'];
        $amount = abs((float) $validated['amount']);
        $isBonus = $type === self::TYPE_BONUS;

        $transaction = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $child->id,
            'amount' => $amount,
            'type' => $type,
            'status' => self::STATUS_APPROVED,
            'comment' => isset($validated['comment']) && trim((string) $validated['comment']) !== '' ? trim((string) $validated['comment']) : ($isBonus ? 'Bonus attribué par le parent.' : 'Pénalité attribuée par le parent.'),
        ]);

        $transaction->load('user:id,name');

        $this->notifyUser(
            (int) $child->id,
            (int) $household->id,
            'budget_adjustment_added',
            $isBonus ? 'Bonus attribué' : 'Pénalité attribuée',
            $isBonus ? sprintf('Un bonus de %0.2f € a été ajouté à ton budget.', $amount) : sprintf('Une pénalité de %0.2f € a été appliquée à ton budget.', $amount),
            ['transaction_id' => (int) $transaction->id, 'user_id' => (int) $child->id, 'amount' => $amount, 'type' => $type, 'status' => (string) $transaction->status],
        );

        $this->publishBudgetRealtime((int) $household->id, 'adjustment.created', [
            'transaction_id' => (int) $transaction->id,
            'user_id' => (int) $child->id,
            'amount' => $amount,
            'adjustment_type' => $type,
        ]);

        return $this->budgetJson([
            'message' => $isBonus ? 'Bonus enregistré.' : 'Pénalité enregistrée.',
            'transaction' => $this->toTransactionPayload($transaction, true),
        ], 201);
    }

    public function updateAdjustment(Request $request, PocketMoneyTransaction $transaction): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTransactionBelongsToHousehold($transaction, $household);
        $this->ensureTransactionIsAdjustment($transaction);

        $validated = $request->validate([
            'type' => ['nullable', 'in:' . self::TYPE_BONUS . ',' . self::TYPE_PENALTY],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        if (!array_key_exists('type', $validated) && !array_key_exists('amount', $validated) && !array_key_exists('comment', $validated)) {
            throw ValidationException::withMessages(['adjustment' => ['Aucune donnée fournie pour la mise à jour de l\'ajustement.']]);
        }

        $nextType = array_key_exists('type', $validated) ? (string) $validated['type'] : (string) $transaction->type;
        $nextAmount = array_key_exists('amount', $validated) ? abs((float) $validated['amount']) : abs((float) $transaction->amount);
        $nextComment = array_key_exists('comment', $validated) ? ((isset($validated['comment']) && trim((string) $validated['comment']) !== '') ? trim((string) $validated['comment']) : null) : $transaction->comment;

        $transaction->update(['type' => $nextType, 'amount' => $nextAmount, 'comment' => $nextComment]);
        $transaction->load('user:id,name');

        $isBonus = $nextType === self::TYPE_BONUS;
        $this->notifyUser(
            (int) $transaction->user_id,
            (int) $household->id,
            'budget_adjustment_updated',
            $isBonus ? 'Bonus mis à jour' : 'Pénalité mise à jour',
            $isBonus ? sprintf('Un bonus de %0.2f € a été mis à jour sur ton budget.', $nextAmount) : sprintf('Une pénalité de %0.2f € a été mise à jour sur ton budget.', $nextAmount),
            ['transaction_id' => (int) $transaction->id, 'user_id' => (int) $transaction->user_id, 'amount' => $nextAmount, 'type' => $nextType, 'status' => (string) $transaction->status],
        );

        $this->publishBudgetRealtime((int) $household->id, 'adjustment.updated', [
            'transaction_id' => (int) $transaction->id,
            'user_id' => (int) $transaction->user_id,
            'amount' => $nextAmount,
            'adjustment_type' => $nextType,
        ]);

        return $this->budgetJson([
            'message' => $isBonus ? 'Bonus mis à jour.' : 'Pénalité mise à jour.',
            'transaction' => $this->toTransactionPayload($transaction, true),
        ]);
    }

    public function deleteAdjustment(Request $request, PocketMoneyTransaction $transaction): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTransactionBelongsToHousehold($transaction, $household);
        $this->ensureTransactionIsAdjustment($transaction);

        $transactionId = (int) $transaction->id;
        $childUserId = (int) $transaction->user_id;
        $amount = abs((float) $transaction->amount);
        $type = (string) $transaction->type;
        $isBonus = $type === self::TYPE_BONUS;

        $transaction->delete();

        $this->notifyUser(
            $childUserId,
            (int) $household->id,
            'budget_adjustment_deleted',
            $isBonus ? 'Bonus supprimé' : 'Pénalité supprimée',
            $isBonus ? sprintf('Un bonus de %0.2f € a été supprimé de ton budget.', $amount) : sprintf('Une pénalité de %0.2f € a été supprimée de ton budget.', $amount),
            ['transaction_id' => $transactionId, 'user_id' => $childUserId, 'amount' => $amount, 'type' => $type],
        );

        $this->publishBudgetRealtime((int) $household->id, 'adjustment.deleted', [
            'transaction_id' => $transactionId,
            'user_id' => $childUserId,
            'amount' => $amount,
            'adjustment_type' => $type,
        ]);

        return $this->budgetJson([
            'message' => $isBonus ? 'Bonus supprimé.' : 'Pénalité supprimée.',
            'deleted_transaction_id' => $transactionId,
        ]);
    }
    public function requestAdvance(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);
        if ($role !== User::ROLE_CHILD) {
            abort(403, 'Action réservée aux enfants.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'comment' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        $currentUser = $request->user();
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

        $requestedAmount = abs((float) $validated['amount']);
        $maxAdvanceAmount = (float) $setting->max_advance_amount;
        if ($maxAdvanceAmount <= 0 || $requestedAmount > $maxAdvanceAmount) {
            throw ValidationException::withMessages([
                'amount' => ["Le montant demandé dépasse la limite autorisée ({$maxAdvanceAmount})."],
            ]);
        }

        return $this->createChildRequest(
            household: $household,
            currentUser: $currentUser,
            amount: $requestedAmount,
            comment: trim((string) $validated['comment']),
            requestKind: self::REQUEST_KIND_ADVANCE,
        );
    }

    public function requestReimbursement(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);
        if ($role !== User::ROLE_CHILD) {
            abort(403, 'Action réservée aux enfants.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'comment' => ['required', 'string', 'min:4', 'max:1000'],
        ]);

        $currentUser = $request->user();
        $setting = BudgetSetting::query()
            ->where('household_id', $household->id)
            ->where('user_id', $currentUser->id)
            ->first();
        if (!$setting) {
            abort(403, 'Aucun paramètre budget configuré pour cet enfant.');
        }

        return $this->createChildRequest(
            household: $household,
            currentUser: $currentUser,
            amount: abs((float) $validated['amount']),
            comment: trim((string) $validated['comment']),
            requestKind: self::REQUEST_KIND_REIMBURSEMENT,
        );
    }

    public function reviewAdvance(Request $request, PocketMoneyTransaction $transaction): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureParentRole($role);
        $this->ensureTransactionBelongsToHousehold($transaction, $household);

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

        $validated = $request->validate([
            'status' => ['required', 'in:' . self::STATUS_APPROVED . ',' . self::STATUS_REJECTED],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'payout_mode' => ['nullable', 'in:' . self::PAYOUT_MODE_INTEGRATED . ',' . self::PAYOUT_MODE_IMMEDIATE],
        ]);

        $meta = $this->extractBudgetCommentMetadata($transaction->comment);
        $requestKind = (string) ($meta['request_kind'] ?? self::REQUEST_KIND_ADVANCE);
        $isReimbursement = $requestKind === self::REQUEST_KIND_REIMBURSEMENT;

        $finalStatus = (string) $validated['status'];
        $finalAmount = $finalStatus === self::STATUS_APPROVED
            ? (array_key_exists('amount', $validated) && $validated['amount'] !== null
                ? abs((float) $validated['amount'])
                : abs((float) $transaction->amount))
            : abs((float) $transaction->amount);

        $payoutMode = null;
        if (!$isReimbursement && array_key_exists('payout_mode', $validated) && $validated['payout_mode'] !== null) {
            throw ValidationException::withMessages([
                'payout_mode' => ['Le mode de paiement est disponible uniquement pour les remboursements.'],
            ]);
        }

        if ($finalStatus === self::STATUS_APPROVED) {
            if ($isReimbursement) {
                $payoutMode = array_key_exists('payout_mode', $validated) && $validated['payout_mode'] !== null
                    ? (string) $validated['payout_mode']
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
                    throw ValidationException::withMessages(['status' => ['Les avances ne sont plus autorisées pour cet enfant.']]);
                }
                $maxAdvanceAmount = (float) $setting->max_advance_amount;
                if ($maxAdvanceAmount <= 0 || $finalAmount > $maxAdvanceAmount) {
                    throw ValidationException::withMessages([
                        'amount' => ["Le montant approuvé dépasse la limite autorisée ({$maxAdvanceAmount})."],
                    ]);
                }
            }
        }

        $reviewComment = isset($validated['comment']) ? trim((string) $validated['comment']) : '';
        $mergedComment = $this->mergeTransactionComment((string) ($meta['display_comment'] ?? ''), $reviewComment);

        $transaction->update([
            'status' => $finalStatus,
            'amount' => $finalAmount,
            'comment' => $this->composeStoredComment(
                comment: $mergedComment,
                requestKind: $requestKind,
                payoutMode: $finalStatus === self::STATUS_APPROVED ? $payoutMode : null,
            ),
        ]);
        $transaction->load('user:id,name');

        $approved = $finalStatus === self::STATUS_APPROVED;
        $modeText = $payoutMode === self::PAYOUT_MODE_IMMEDIATE
            ? ' et payée immédiatement'
            : ($payoutMode === self::PAYOUT_MODE_INTEGRATED ? ' et intégrée au paiement' : '');

        $this->notifyUser(
            (int) $transaction->user_id,
            (int) $household->id,
            $isReimbursement ? 'budget_reimbursement_reviewed' : 'budget_advance_reviewed',
            $approved ? ($isReimbursement ? 'Demande de remboursement approuvée' : 'Demande d\'avance approuvée') : ($isReimbursement ? 'Demande de remboursement refusée' : 'Demande d\'avance refusée'),
            $approved
                ? ($isReimbursement
                    ? sprintf('Ta demande de remboursement de %0.2f € est approuvée%s.', abs($finalAmount), $modeText)
                    : sprintf('Ta demande d\'avance de %0.2f € a été approuvée.', abs($finalAmount)))
                : sprintf('Ta demande de %0.2f € a été refusée.', abs($finalAmount)),
            [
                'transaction_id' => (int) $transaction->id,
                'user_id' => (int) $transaction->user_id,
                'amount' => abs((float) $transaction->amount),
                'status' => (string) $transaction->status,
                'request_kind' => $requestKind,
                'payout_mode' => $payoutMode,
            ],
        );

        $this->publishBudgetRealtime((int) $household->id, 'advance.reviewed', [
            'transaction_id' => (int) $transaction->id,
            'user_id' => (int) $transaction->user_id,
            'amount' => abs((float) $transaction->amount),
            'status' => (string) $transaction->status,
            'request_kind' => $requestKind,
            'payout_mode' => $payoutMode,
        ]);

        return $this->budgetJson([
            'message' => $approved
                ? ($isReimbursement ? 'Demande de remboursement approuvée.' : 'Demande d\'avance approuvée.')
                : ($isReimbursement ? 'Demande de remboursement refusée.' : 'Demande d\'avance refusée.'),
            'transaction' => $this->toTransactionPayload($transaction, true),
        ]);
    }

    private function createChildRequest(Household $household, User $currentUser, float $amount, string $comment, string $requestKind): JsonResponse
    {
        $tx = PocketMoneyTransaction::query()->create([
            'household_id' => $household->id,
            'user_id' => $currentUser->id,
            'amount' => abs($amount),
            'type' => self::TYPE_ADVANCE,
            'status' => self::STATUS_PENDING,
            'comment' => $this->composeStoredComment($comment, $requestKind, null),
        ]);
        $tx->load('user:id,name');

        $isReimbursement = $requestKind === self::REQUEST_KIND_REIMBURSEMENT;
        $this->notifyUsers(
            $this->resolveParentUserIds($household),
            (int) $household->id,
            $isReimbursement ? 'budget_reimbursement_requested' : 'budget_advance_requested',
            $isReimbursement ? 'Nouvelle demande de remboursement' : 'Nouvelle demande d\'avance',
            $isReimbursement
                ? sprintf('%s demande un remboursement de %0.2f €.', (string) ($currentUser->name ?? 'Un enfant'), abs($amount))
                : sprintf('%s a demandé une avance de %0.2f €.', (string) ($currentUser->name ?? 'Un enfant'), abs($amount)),
            [
                'transaction_id' => (int) $tx->id,
                'user_id' => (int) $currentUser->id,
                'amount' => abs($amount),
                'status' => (string) $tx->status,
                'request_kind' => $requestKind,
            ],
        );

        $this->publishBudgetRealtime((int) $household->id, 'advance.requested', [
            'transaction_id' => (int) $tx->id,
            'user_id' => (int) $currentUser->id,
            'amount' => abs((float) $tx->amount),
            'status' => (string) $tx->status,
            'request_kind' => $requestKind,
        ]);

        return $this->budgetJson([
            'message' => $isReimbursement ? 'Demande de remboursement envoyée.' : 'Demande d\'avance envoyée.',
            'transaction' => $this->toTransactionPayload($tx, true),
        ], 201);
    }

    private function ensureBudgetModuleEnabled(Household $household): void
    {
        $settings = HouseholdSetting::query()->where('household_id', $household->id)->first();
        if (!(bool) ($settings?->has_budget ?? false)) {
            abort(403, 'Le module budget est désactivé pour ce foyer.');
        }
    }

    private function resolveBudgetConfig(Household $household): array
    {
        $settings = HouseholdSetting::query()->where('household_id', $household->id)->first();
        $config = is_array($settings?->budget_config) ? $settings->budget_config : [];
        if (!isset($config['currency']) || !is_string($config['currency']) || trim($config['currency']) === '') {
            $config['currency'] = 'EUR';
        }
        return $config;
    }

    private function ensureChildBelongsToHousehold(Household $household, int $userId): User
    {
        $member = $household->users()->select('users.id', 'users.name')->where('users.id', $userId)->first();
        if (!$member) {
            throw ValidationException::withMessages(['user_id' => ["Le membre sélectionné n'appartient pas au foyer."]]);
        }
        $memberRole = (string) ($member->pivot->role ?? User::ROLE_CHILD);
        if ($memberRole !== User::ROLE_CHILD) {
            throw ValidationException::withMessages(['user_id' => ['Le budget argent de poche est réservé aux membres enfant.']]);
        }
        return $member;
    }

    private function ensureTransactionBelongsToHousehold(PocketMoneyTransaction $transaction, Household $household): void
    {
        if ((int) $transaction->household_id !== (int) $household->id) {
            abort(404, 'Transaction introuvable.');
        }
    }

    private function ensureTransactionIsAdjustment(PocketMoneyTransaction $transaction): void
    {
        $type = (string) $transaction->type;
        if ($type !== self::TYPE_BONUS && $type !== self::TYPE_PENALTY) {
            throw ValidationException::withMessages(['transaction' => ['Seuls les bonus et les pénalités peuvent être modifiés ou supprimés ici.']]);
        }
    }

    private function toChildBudgetPayload(User $child, ?BudgetSetting $setting, Collection $transactions, Carbon $now): array
    {
        $recurrence = (string) ($setting?->recurrence ?? 'weekly');
        $resetDay = $this->normalizeResetDay((int) ($setting?->reset_day ?? 1), $recurrence);
        [$periodStart, $periodEnd] = $this->resolvePeriodBoundaries($recurrence, $resetDay, $now);

        $periodTransactions = $transactions->filter(function (PocketMoneyTransaction $tx) use ($periodStart, $periodEnd): bool {
            $date = $tx->created_at ?? $tx->updated_at;
            return $date instanceof Carbon && $date->between($periodStart, $periodEnd, true);
        })->values();

        $approved = $periodTransactions->where('status', self::STATUS_APPROVED)->values();
        $approvedAdvances = $approved->where('type', self::TYPE_ADVANCE)->filter(fn(PocketMoneyTransaction $tx): bool => $this->resolveAdvanceRequestKind($tx) === self::REQUEST_KIND_ADVANCE);
        $pendingAdvances = $periodTransactions->where('type', self::TYPE_ADVANCE)->where('status', self::STATUS_PENDING)->filter(fn(PocketMoneyTransaction $tx): bool => $this->resolveAdvanceRequestKind($tx) === self::REQUEST_KIND_ADVANCE);
        $approvedReimbursements = $approved->where('type', self::TYPE_ADVANCE)->filter(fn(PocketMoneyTransaction $tx): bool => $this->resolveAdvanceRequestKind($tx) === self::REQUEST_KIND_REIMBURSEMENT);
        $pendingReimbursements = $periodTransactions->where('type', self::TYPE_ADVANCE)->where('status', self::STATUS_PENDING)->filter(fn(PocketMoneyTransaction $tx): bool => $this->resolveAdvanceRequestKind($tx) === self::REQUEST_KIND_REIMBURSEMENT);
        $lifetimeApproved = $transactions->where('status', self::STATUS_APPROVED)->values();

        return [
            'child' => ['id' => (int) $child->id, 'name' => (string) $child->name],
            'is_configured' => $setting !== null,
            'setting' => $setting ? $this->toSettingPayload($setting) : null,
            'period' => ['start' => $periodStart->toDateString(), 'end' => $periodEnd->toDateString(), 'label' => $recurrence === 'monthly' ? 'Mensuel' : 'Hebdomadaire'],
            'summary' => [
                'base_amount' => $setting ? (float) $setting->base_amount : 0.0,
                'approved_total_period' => $this->sumTransactions($approved),
                'allocation_total_period' => $this->sumTransactionsByType($approved, self::TYPE_ALLOCATION),
                'bonus_total_period' => $this->sumTransactionsByType($approved, self::TYPE_BONUS),
                'penalty_total_period' => $this->sumTransactionsByType($approved, self::TYPE_PENALTY),
                'advance_total_period' => $approvedAdvances->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount)),
                'pending_advance_total_period' => $pendingAdvances->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount)),
                'reimbursement_total_period' => $approvedReimbursements->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount)),
                'pending_reimbursement_total_period' => $pendingReimbursements->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount)),
                'lifetime_balance' => $this->sumTransactions($lifetimeApproved),
            ],
            'transactions' => $transactions->take(12)->map(fn(PocketMoneyTransaction $tx): array => $this->toTransactionPayload($tx, true))->values(),
        ];
    }

    private function toSettingPayload(BudgetSetting $setting): array
    {
        return [
            'id' => (int) $setting->id,
            'household_id' => (int) $setting->household_id,
            'user_id' => (int) $setting->user_id,
            'base_amount' => (float) $setting->base_amount,
            'recurrence' => (string) $setting->recurrence,
            'reset_day' => (int) $setting->reset_day,
            'allow_advances' => (bool) $setting->allow_advances,
            'max_advance_amount' => (float) $setting->max_advance_amount,
        ];
    }

    private function toTransactionPayload(PocketMoneyTransaction $transaction, bool $includeUser = false): array
    {
        $displayComment = $transaction->comment;
        $requestKind = null;
        $payoutMode = null;
        if ((string) $transaction->type === self::TYPE_ADVANCE) {
            $meta = $this->extractBudgetCommentMetadata($transaction->comment);
            $displayComment = $meta['display_comment'];
            $requestKind = $meta['request_kind'];
            $payoutMode = $meta['payout_mode'];
        }

        $payload = [
            'id' => (int) $transaction->id,
            'household_id' => (int) $transaction->household_id,
            'user_id' => (int) $transaction->user_id,
            'amount' => abs((float) $transaction->amount),
            'signed_amount' => $this->signedTransactionAmount($transaction),
            'type' => (string) $transaction->type,
            'status' => (string) $transaction->status,
            'comment' => $displayComment,
            'request_kind' => $requestKind,
            'payout_mode' => $payoutMode,
            'created_at' => optional($transaction->created_at)->toIso8601String(),
            'updated_at' => optional($transaction->updated_at)->toIso8601String(),
        ];

        if ($includeUser) {
            $payload['user'] = [
                'id' => (int) ($transaction->user?->id ?? 0),
                'name' => (string) ($transaction->user?->name ?? ''),
            ];
        }

        return $payload;
    }

    private function resolvePeriodBoundaries(string $recurrence, int $resetDay, Carbon $now): array
    {
        $today = $now->copy()->startOfDay();
        if ($recurrence === 'monthly') {
            $currentMonthStart = $today->copy()->startOfMonth();
            $periodStart = $this->resolveMonthlyResetDate($currentMonthStart, $resetDay);
            if ($today->lt($periodStart)) {
                $periodStart = $this->resolveMonthlyResetDate($currentMonthStart->copy()->subMonthNoOverflow()->startOfMonth(), $resetDay);
            }
            $nextMonth = $periodStart->copy()->addMonthNoOverflow()->startOfMonth();
            $nextPeriodStart = $this->resolveMonthlyResetDate($nextMonth, $resetDay);
            return [$periodStart, $nextPeriodStart->copy()->subSecond()];
        }

        $safeResetDay = max(1, min(7, $resetDay));
        $delta = ((int) $today->isoWeekday() - $safeResetDay + 7) % 7;
        $periodStart = $today->copy()->subDays($delta);
        return [$periodStart, $periodStart->copy()->addDays(7)->subSecond()];
    }

    private function normalizeResetDay(int $value, string $recurrence): int
    {
        return $recurrence === 'monthly' ? max(1, min(31, $value)) : max(1, min(7, $value));
    }

    private function resolveMonthlyResetDate(Carbon $monthStart, int $resetDay): Carbon
    {
        $safeResetDay = max(1, min(31, $resetDay));
        $day = min($safeResetDay, (int) $monthStart->daysInMonth);
        return $monthStart->copy()->day($day)->startOfDay();
    }

    private function sumTransactions(Collection $transactions): float
    {
        return $transactions->sum(fn(PocketMoneyTransaction $tx): float => $this->signedTransactionAmount($tx));
    }

    private function sumTransactionsByType(Collection $transactions, string $type): float
    {
        return $transactions->where('type', $type)->sum(fn(PocketMoneyTransaction $tx): float => $this->signedTransactionAmount($tx));
    }

    private function signedTransactionAmount(PocketMoneyTransaction $transaction): float
    {
        $amount = abs((float) $transaction->amount);
        $type = (string) $transaction->type;
        if ($type === self::TYPE_PENALTY) {
            return -$amount;
        }
        if ($type === self::TYPE_ADVANCE && $this->resolveAdvanceRequestKind($transaction) === self::REQUEST_KIND_REIMBURSEMENT) {
            return 0.0;
        }
        return $amount;
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

    private function extractBudgetCommentMetadata(?string $storedComment): array
    {
        $raw = (string) $storedComment;
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['request_kind' => self::REQUEST_KIND_ADVANCE, 'payout_mode' => null, 'display_comment' => null];
        }

        $lines = preg_split('/\R/u', $raw) ?: [];
        $first = trim((string) ($lines[0] ?? ''));
        if (!str_starts_with($first, self::COMMENT_META_PREFIX)) {
            return ['request_kind' => self::REQUEST_KIND_ADVANCE, 'payout_mode' => null, 'display_comment' => $trimmed];
        }

        $requestKind = self::REQUEST_KIND_ADVANCE;
        $payoutMode = null;
        $metaRaw = trim(substr($first, strlen(self::COMMENT_META_PREFIX)));
        foreach (explode(';', $metaRaw) as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $chunk, 2));
            if ($k === 'request_kind' && in_array($v, [self::REQUEST_KIND_ADVANCE, self::REQUEST_KIND_REIMBURSEMENT], true)) {
                $requestKind = $v;
            }
            if ($k === 'payout_mode' && in_array($v, [self::PAYOUT_MODE_INTEGRATED, self::PAYOUT_MODE_IMMEDIATE], true)) {
                $payoutMode = $v;
            }
        }

        $display = trim(implode("\n", array_slice($lines, 1)));
        return ['request_kind' => $requestKind, 'payout_mode' => $payoutMode, 'display_comment' => $display === '' ? null : $display];
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

    private function resolveAdvanceRequestKind(PocketMoneyTransaction $transaction): string
    {
        if ((string) $transaction->type !== self::TYPE_ADVANCE) {
            return self::REQUEST_KIND_ADVANCE;
        }
        $meta = $this->extractBudgetCommentMetadata($transaction->comment);
        return (string) ($meta['request_kind'] ?? self::REQUEST_KIND_ADVANCE);
    }

    private function resetCurrentPeriodAdjustmentsForChild(int $householdId, int $childUserId, ?BudgetSetting $setting): int
    {
        $recurrence = (string) ($setting?->recurrence ?? 'weekly');
        $resetDay = $this->normalizeResetDay((int) ($setting?->reset_day ?? 1), $recurrence);
        [$periodStart, $periodEnd] = $this->resolvePeriodBoundaries($recurrence, $resetDay, now());

        $adjustments = PocketMoneyTransaction::query()
            ->where('household_id', $householdId)
            ->where('user_id', $childUserId)
            ->whereIn('type', [self::TYPE_BONUS, self::TYPE_PENALTY])
            ->where('status', self::STATUS_APPROVED)
            ->where('created_at', '>=', $periodStart)
            ->where('created_at', '<=', $periodEnd)
            ->get();

        foreach ($adjustments as $tx) {
            $tx->update([
                'status' => self::STATUS_REJECTED,
                'comment' => $this->mergeTransactionComment($tx->comment, 'Ajustement appliqué et réinitialisé lors du paiement.'),
            ]);
        }

        return $adjustments->count();
    }

    /**
     * @return array{0: float, 1: Carbon, 2: Carbon}
     */
    private function computeCurrentPeriodRemainingRaw(int $householdId, int $childUserId, ?BudgetSetting $setting): array
    {
        $recurrence = (string) ($setting?->recurrence ?? 'weekly');
        $resetDay = $this->normalizeResetDay((int) ($setting?->reset_day ?? 1), $recurrence);
        [$periodStart, $periodEnd] = $this->resolvePeriodBoundaries($recurrence, $resetDay, now());

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
            ->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount));
        $penaltyTotal = $approved
            ->where('type', self::TYPE_PENALTY)
            ->sum(static fn(PocketMoneyTransaction $tx): float => -abs((float) $tx->amount));
        $advanceToDeduct = $approved
            ->where('type', self::TYPE_ADVANCE)
            ->filter(fn(PocketMoneyTransaction $tx): bool => $this->resolveAdvanceRequestKind($tx) === self::REQUEST_KIND_ADVANCE)
            ->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount));
        $alreadyPaid = $approved
            ->where('type', self::TYPE_ALLOCATION)
            ->sum(static fn(PocketMoneyTransaction $tx): float => abs((float) $tx->amount));

        $totalExpected = $baseAmount + $bonusTotal + $penaltyTotal - $advanceToDeduct;
        $remainingRaw = $totalExpected - $alreadyPaid;

        return [$remainingRaw, $periodStart, $periodEnd];
    }

    private function budgetJson(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    private function publishBudgetRealtime(int $householdId, string $type, array $payload = []): void
    {
        $this->realtimePublisher->publishHousehold(
            householdId: $householdId,
            module: 'budget',
            type: $type,
            payload: $payload + ['household_id' => $householdId],
        );
    }

    private function notifyUser(int $userId, int $householdId, string $type, string $title, string $body, array $data = []): void
    {
        if ($userId <= 0 || $householdId <= 0) {
            return;
        }

        $notification = UserNotification::query()->create([
            'household_id' => $householdId,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data + ['household_id' => $householdId],
        ]);

        $this->realtimePublisher->publishUser(
            userId: $userId,
            module: 'notifications',
            type: 'notification_created',
            payload: [
                'notification_id' => (int) $notification->id,
                'household_id' => $householdId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
            ],
        );
    }

    /** @param array<int,int> $userIds */
    private function notifyUsers(array $userIds, int $householdId, string $type, string $title, string $body, array $data = []): void
    {
        $ids = collect($userIds)->map(static fn(mixed $id): int => (int) $id)->filter(static fn(int $id): bool => $id > 0)->unique()->values()->all();
        foreach ($ids as $userId) {
            $this->notifyUser((int) $userId, $householdId, $type, $title, $body, $data);
        }
    }

    /** @return array<int,int> */
    private function resolveParentUserIds(Household $household): array
    {
        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->pluck('users.id')
            ->map(static fn(mixed $id): int => (int) $id)
            ->filter(static fn(int $id): bool => $id > 0)
            ->values()
            ->all();
    }
}

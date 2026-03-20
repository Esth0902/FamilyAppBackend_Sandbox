<?php

namespace App\Http\Resources\Budget;

use App\Domain\Budget\Services\BudgetCalculationService;
use App\Models\BudgetSetting;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ChildBudgetResource extends JsonResource
{
    private const TYPE_ALLOCATION = 'allocation';
    private const TYPE_BONUS = 'bonus';
    private const TYPE_PENALTY = 'penalty';
    private const TYPE_ADVANCE = 'advance';

    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';

    private const REQUEST_KIND_ADVANCE = 'advance';
    private const REQUEST_KIND_REIMBURSEMENT = 'reimbursement';

    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $child */
        $child = $this->resource['child'] ?? null;
        /** @var BudgetSetting|null $setting */
        $setting = $this->resource['setting'] ?? null;
        $transactions = $this->resource['transactions'] ?? collect();
        $now = $this->resource['now'] ?? now();

        if (!$transactions instanceof Collection) {
            $transactions = collect($transactions);
        }

        if (!$now instanceof Carbon) {
            $now = Carbon::parse((string) $now);
        }

        $calculationService = app(BudgetCalculationService::class);
        $recurrence = (string) ($setting?->recurrence ?? 'weekly');
        $resetDay = $calculationService->normalizeResetDay((int) ($setting?->reset_day ?? 1), $recurrence);
        [$periodStart, $periodEnd] = $calculationService->resolvePeriodBoundaries($recurrence, $resetDay, $now);

        $allPayloadsByTransactionId = $transactions
            ->mapWithKeys(function (PocketMoneyTransaction $transaction) use ($request): array {
                return [(int) $transaction->id => (new TransactionResource($transaction))->resolve($request)];
            });

        $periodTransactions = $transactions->filter(function (PocketMoneyTransaction $transaction) use ($periodStart, $periodEnd): bool {
            $date = $transaction->created_at ?? $transaction->updated_at;

            return $date instanceof Carbon && $date->between($periodStart, $periodEnd, true);
        })->values();

        $periodPayloads = $periodTransactions
            ->map(function (PocketMoneyTransaction $transaction) use ($allPayloadsByTransactionId): array {
                return (array) ($allPayloadsByTransactionId->get((int) $transaction->id) ?? []);
            })
            ->values();

        $approvedPeriodPayloads = $periodPayloads
            ->where('status', self::STATUS_APPROVED)
            ->values();

        $approvedAdvances = $approvedPeriodPayloads
            ->where('type', self::TYPE_ADVANCE)
            ->where('request_kind', self::REQUEST_KIND_ADVANCE);

        $pendingAdvances = $periodPayloads
            ->where('type', self::TYPE_ADVANCE)
            ->where('status', self::STATUS_PENDING)
            ->where('request_kind', self::REQUEST_KIND_ADVANCE);

        $approvedReimbursements = $approvedPeriodPayloads
            ->where('type', self::TYPE_ADVANCE)
            ->where('request_kind', self::REQUEST_KIND_REIMBURSEMENT);

        $pendingReimbursements = $periodPayloads
            ->where('type', self::TYPE_ADVANCE)
            ->where('status', self::STATUS_PENDING)
            ->where('request_kind', self::REQUEST_KIND_REIMBURSEMENT);

        $lifetimeApprovedPayloads = $allPayloadsByTransactionId
            ->values()
            ->where('status', self::STATUS_APPROVED)
            ->values();

        return [
            'child' => [
                'id' => (int) ($child?->id ?? 0),
                'name' => (string) ($child?->name ?? ''),
            ],
            'is_configured' => $setting !== null,
            'setting' => $setting ? (new BudgetSettingResource($setting))->resolve($request) : null,
            'period' => [
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
                'label' => $recurrence === 'monthly' ? 'Mensuel' : 'Hebdomadaire',
            ],
            'summary' => [
                'base_amount' => $setting ? (float) $setting->base_amount : 0.0,
                'approved_total_period' => $this->sumSignedAmounts($approvedPeriodPayloads),
                'allocation_total_period' => $this->sumSignedAmounts($approvedPeriodPayloads->where('type', self::TYPE_ALLOCATION)->values()),
                'bonus_total_period' => $this->sumSignedAmounts($approvedPeriodPayloads->where('type', self::TYPE_BONUS)->values()),
                'penalty_total_period' => $this->sumSignedAmounts($approvedPeriodPayloads->where('type', self::TYPE_PENALTY)->values()),
                'advance_total_period' => (float) $approvedAdvances->sum('amount'),
                'pending_advance_total_period' => (float) $pendingAdvances->sum('amount'),
                'reimbursement_total_period' => (float) $approvedReimbursements->sum('amount'),
                'pending_reimbursement_total_period' => (float) $pendingReimbursements->sum('amount'),
                'lifetime_balance' => $this->sumSignedAmounts($lifetimeApprovedPayloads),
            ],
            'transactions' => $allPayloadsByTransactionId->values()->take(12)->values()->all(),
        ];
    }

    private function sumSignedAmounts(Collection $transactionPayloads): float
    {
        return (float) $transactionPayloads->sum(static function (mixed $transaction): float {
            if (!is_array($transaction)) {
                return 0.0;
            }

            return (float) ($transaction['signed_amount'] ?? 0.0);
        });
    }
}

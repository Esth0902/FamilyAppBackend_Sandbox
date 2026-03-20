<?php

namespace App\Actions\Budget;

use App\Actions\Budget\Concerns\InteractsWithBudgetContext;
use App\DTOs\Budget\AdvanceRequestDTO;
use App\Domain\Budget\Services\BudgetCalculationService;
use App\Domain\Budget\ValueObjects\BudgetComment;
use App\Events\Budget\AdvanceRequestedEvent;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RequestAdvanceAction
{
    use InteractsWithBudgetContext;

    private const TYPE_ADVANCE = 'advance';
    private const STATUS_PENDING = 'pending';
    private const REQUEST_KIND_ADVANCE = 'advance';

    public function __construct(private readonly BudgetCalculationService $budgetCalculationService)
    {
    }

    public function execute(
        Household $household,
        string $currentRole,
        User $currentUser,
        AdvanceRequestDTO $dto
    ): PocketMoneyTransaction
    {
        $this->ensureBudgetModuleEnabled($household);
        $this->ensureChildRole($currentRole);

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
            'comment' => new BudgetComment($dto->comment, self::REQUEST_KIND_ADVANCE),
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
}

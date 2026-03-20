<?php

namespace App\Http\Controllers\Api;

use App\Actions\Budget\CreateAdjustmentAction;
use App\Actions\Budget\DeleteAdjustmentAction;
use App\Actions\Budget\RequestAdvanceAction;
use App\Actions\Budget\RequestReimbursementAction;
use App\Actions\Budget\Results\ValidatePaymentResult;
use App\Actions\Budget\ReviewAdvanceAction;
use App\Actions\Budget\UpdateAdjustmentAction;
use App\Actions\Budget\UpdateBudgetSettingAction;
use App\Actions\Budget\ValidatePaymentAction;
use App\DTOs\Budget\AdvanceRequestDTO;
use App\Http\Controllers\Api\Concerns\ResolvesHouseholdContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\CreateAdjustmentRequest;
use App\Http\Requests\Budget\DeleteAdjustmentRequest;
use App\Http\Requests\Budget\RequestAdvanceRequest;
use App\Http\Requests\Budget\RequestReimbursementRequest;
use App\Http\Requests\Budget\ReviewAdvanceRequest;
use App\Http\Requests\Budget\UpdateAdjustmentRequest;
use App\Http\Requests\Budget\UpdateBudgetSettingRequest;
use App\Http\Requests\Budget\ValidatePaymentRequest;
use App\Http\Resources\Budget\BudgetSettingResource;
use App\Http\Resources\Budget\TransactionResource;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use App\Queries\Budget\GetBudgetDashboardQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    use ResolvesHouseholdContext;

    public function __construct(
        private readonly RequestAdvanceAction $requestAdvanceAction,
        private readonly RequestReimbursementAction $requestReimbursementAction,
        private readonly UpdateBudgetSettingAction $updateBudgetSettingAction,
        private readonly ValidatePaymentAction $validatePaymentAction,
        private readonly CreateAdjustmentAction $createAdjustmentAction,
        private readonly UpdateAdjustmentAction $updateAdjustmentAction,
        private readonly DeleteAdjustmentAction $deleteAdjustmentAction,
        private readonly ReviewAdvanceAction $reviewAdvanceAction,
        private readonly GetBudgetDashboardQuery $getBudgetDashboardQuery,
    ) {
    }

    public function board(Request $request): JsonResponse
    {
        [$household, $role] = $this->resolveHouseholdWithRole($request);
        $payload = $this->getBudgetDashboardQuery->execute($household, (string) $role, (int) $request->user()->id, $request);

        return $this->budgetJson($payload);
    }

    public function updateSetting(UpdateBudgetSettingRequest $request, User $user): JsonResponse
    {
        $setting = $this->updateBudgetSettingAction->execute($request->household(), $request->householdRole(), (int) $user->id, $request->validated());

        return $this->budgetJson([
            'message' => 'Paramètres du budget mis à jour.',
            'setting' => (new BudgetSettingResource($setting))->resolve($request),
        ]);
    }

    public function validatePayment(ValidatePaymentRequest $request): JsonResponse
    {
        return $this->respondValidatePaymentResult(
            $this->validatePaymentAction->execute($request->household(), $request->householdRole(), $request->validated()),
            $request,
        );
    }

    public function createAdjustment(CreateAdjustmentRequest $request): JsonResponse
    {
        $result = $this->createAdjustmentAction->execute($request->household(), $request->householdRole(), $request->validated());

        return $this->budgetJson([
            'message' => $result->message,
            'transaction' => (new TransactionResource($result->transaction))->resolve($request),
        ], $result->statusCode);
    }

    public function updateAdjustment(UpdateAdjustmentRequest $request, PocketMoneyTransaction $transaction): JsonResponse
    {
        $result = $this->updateAdjustmentAction->execute($request->household(), $request->householdRole(), $transaction, $request->validated());

        return $this->budgetJson([
            'message' => $result->message,
            'transaction' => (new TransactionResource($result->transaction))->resolve($request),
        ]);
    }

    public function deleteAdjustment(DeleteAdjustmentRequest $request, PocketMoneyTransaction $transaction): JsonResponse
    {
        $result = $this->deleteAdjustmentAction->execute($request->household(), $request->householdRole(), $transaction);

        return $this->budgetJson([
            'message' => $result->message,
            'deleted_transaction_id' => $result->deletedTransactionId,
        ]);
    }

    public function requestAdvance(RequestAdvanceRequest $request): JsonResponse
    {
        return $this->respondCreatedTransaction(
            'Demande d\'avance envoyée.',
            $this->executeAdvanceRequest($request),
            $request,
        );
    }

    public function requestReimbursement(RequestReimbursementRequest $request): JsonResponse
    {
        return $this->respondCreatedTransaction(
            'Demande de remboursement envoyée.',
            $this->executeReimbursementRequest($request),
            $request,
        );
    }

    public function reviewAdvance(ReviewAdvanceRequest $request, PocketMoneyTransaction $transaction): JsonResponse
    {
        $result = $this->reviewAdvanceAction->execute($request->household(), $request->householdRole(), $transaction, $request->validated());

        return $this->budgetJson([
            'message' => $result->message,
            'transaction' => (new TransactionResource($result->transaction))->resolve($request),
        ]);
    }

    private function respondValidatePaymentResult(ValidatePaymentResult $result, Request $request): JsonResponse
    {
        if ($result->transaction instanceof PocketMoneyTransaction) {
            return $this->budgetJson([
                'message' => $result->message,
                'transaction' => (new TransactionResource($result->transaction))->resolve($request),
            ], $result->statusCode);
        }

        return $this->budgetJson([
            'message' => $result->message,
            'carry_amount' => $result->carryAmount,
            'period_start' => $result->periodStart,
            'period_end' => $result->periodEnd,
            'next_period_start' => $result->nextPeriodStart,
        ], $result->statusCode);
    }

    private function makeAdvanceRequestDto(RequestAdvanceRequest $request): AdvanceRequestDTO
    {
        return new AdvanceRequestDTO(
            amount: abs((float) $request->validated('amount')),
            comment: trim((string) $request->validated('comment')),
        );
    }

    private function executeAdvanceRequest(RequestAdvanceRequest $request): PocketMoneyTransaction
    {
        return $this->requestAdvanceAction->execute(
            $request->household(),
            $request->householdRole(),
            $request->user(),
            $this->makeAdvanceRequestDto($request),
        );
    }

    private function executeReimbursementRequest(RequestReimbursementRequest $request): PocketMoneyTransaction
    {
        return $this->requestReimbursementAction->execute(
            $request->household(),
            $request->householdRole(),
            $request->user(),
            abs((float) $request->validated('amount')),
            trim((string) $request->validated('comment')),
        );
    }

    private function respondCreatedTransaction(string $message, PocketMoneyTransaction $transaction, Request $request): JsonResponse
    {
        return $this->budgetJson([
            'message' => $message,
            'transaction' => (new TransactionResource($transaction))->resolve($request),
        ], 201);
    }

    private function budgetJson(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}

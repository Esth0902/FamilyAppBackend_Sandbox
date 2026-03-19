<?php

namespace App\Queries\Budget;

use App\Http\Resources\Budget\ChildBudgetResource;
use App\Http\Resources\Budget\TransactionResource;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\HouseholdSetting;
use App\Models\PocketMoneyTransaction;
use App\Models\User;
use Illuminate\Http\Request;

class GetBudgetDashboardQuery
{
    private const TYPE_ADVANCE = 'advance';
    private const STATUS_PENDING = 'pending';

    /**
     * @return array<string, mixed>
     */
    public function execute(Household $household, string $role, int $currentUserId, Request $request): array
    {
        $this->ensureBudgetModuleEnabled($household);

        $isParent = $role === User::ROLE_PARENT;

        $children = $household->users()
            ->select('users.id', 'users.name')
            ->wherePivot('role', User::ROLE_CHILD)
            ->orderBy('users.name')
            ->get();

        $targetChildren = $isParent ? $children : $children->where('id', $currentUserId)->values();
        $childIds = $targetChildren->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values();

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

        $childrenPayload = $targetChildren->map(function (User $child) use ($settingsByUserId, $transactionsByUserId, $request): array {
            /** @var BudgetSetting|null $setting */
            $setting = $settingsByUserId->get((int) $child->id);
            $transactions = $transactionsByUserId->get((int) $child->id, collect());

            return (new ChildBudgetResource([
                'child' => $child,
                'setting' => $setting,
                'transactions' => $transactions,
                'now' => now(),
            ]))->resolve($request);
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
                ->map(fn (PocketMoneyTransaction $tx): array => (new TransactionResource($tx))->resolve($request))
                ->values()
                ->all();
        }

        $budgetConfig = $this->resolveBudgetConfig($household);

        return [
            'budget_enabled' => true,
            'currency' => (string) ($budgetConfig['currency'] ?? 'EUR'),
            'settings' => $budgetConfig,
            'current_user' => ['id' => $currentUserId, 'role' => $role],
            'children' => $childrenPayload,
            'pending_advance_requests' => $pendingRequests,
        ];
    }

    private function ensureBudgetModuleEnabled(Household $household): void
    {
        $settings = HouseholdSetting::query()->where('household_id', $household->id)->first();
        if (!(bool) ($settings?->has_budget ?? false)) {
            abort(403, 'Le module budget est désactivé pour ce foyer.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveBudgetConfig(Household $household): array
    {
        $settings = HouseholdSetting::query()->where('household_id', $household->id)->first();
        $config = is_array($settings?->budget_config) ? $settings->budget_config : [];
        if (!isset($config['currency']) || !is_string($config['currency']) || trim($config['currency']) === '') {
            $config['currency'] = 'EUR';
        }

        return $config;
    }
}

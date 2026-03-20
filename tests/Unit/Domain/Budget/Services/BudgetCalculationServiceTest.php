<?php

namespace Tests\Unit\Domain\Budget\Services;

use App\Domain\Budget\Services\BudgetCalculationService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class BudgetCalculationServiceTest extends TestCase
{
    public function test_it_normalizes_requested_amount_to_a_positive_float(): void
    {
        $service = new BudgetCalculationService();

        $this->assertSame(15.25, $service->normalizeRequestedAmount(-15.25));
        $this->assertSame(20.0, $service->normalizeRequestedAmount('20'));
    }

    public function test_it_validates_advance_limit_rules(): void
    {
        $service = new BudgetCalculationService();

        $this->assertTrue($service->isAdvanceAmountAllowed(25.0, 25.0));
        $this->assertFalse($service->isAdvanceAmountAllowed(25.01, 25.0));
        $this->assertFalse($service->isAdvanceAmountAllowed(10.0, 0.0));
    }

    public function test_it_normalizes_reset_day_for_weekly_and_monthly_recurrence(): void
    {
        $service = new BudgetCalculationService();

        $this->assertSame(1, $service->normalizeResetDay(0, 'weekly'));
        $this->assertSame(7, $service->normalizeResetDay(10, 'weekly'));
        $this->assertSame(1, $service->normalizeResetDay(-5, 'monthly'));
        $this->assertSame(31, $service->normalizeResetDay(45, 'monthly'));
    }

    public function test_it_resolves_weekly_period_boundaries(): void
    {
        $service = new BudgetCalculationService();

        [$start, $end] = $service->resolvePeriodBoundaries(
            recurrence: 'weekly',
            resetDay: 1,
            now: Carbon::parse('2026-03-19 15:30:00')
        );

        $this->assertSame('2026-03-16 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-22 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_it_resolves_monthly_period_boundaries_even_before_reset_day(): void
    {
        $service = new BudgetCalculationService();

        [$start, $end] = $service->resolvePeriodBoundaries(
            recurrence: 'monthly',
            resetDay: 10,
            now: Carbon::parse('2026-03-05 10:00:00')
        );

        $this->assertSame('2026-02-10 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_it_calculates_signed_transaction_amount_by_type_and_request_kind(): void
    {
        $service = new BudgetCalculationService();

        $this->assertSame(-12.5, $service->signedTransactionAmount(12.5, 'penalty'));
        $this->assertSame(0.0, $service->signedTransactionAmount(12.5, 'advance', 'reimbursement'));
        $this->assertSame(12.5, $service->signedTransactionAmount(-12.5, 'bonus'));
    }

    public function test_it_calculates_remaining_raw_amount(): void
    {
        $service = new BudgetCalculationService();

        $remaining = $service->calculateRemainingRaw(
            baseAmount: 100.0,
            bonusTotal: 20.0,
            penaltyTotal: -5.0,
            advanceToDeduct: 30.0,
            alreadyPaid: 40.0
        );

        $this->assertSame(45.0, $remaining);
    }
}

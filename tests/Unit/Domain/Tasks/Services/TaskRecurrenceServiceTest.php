<?php

namespace Tests\Unit\Domain\Tasks\Services;

use App\Domain\Tasks\Services\TaskRecurrenceService;
use App\Models\TaskTemplate;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaskRecurrenceServiceTest extends TestCase
{
    public function test_it_normalizes_recurrence_and_days_consistently(): void
    {
        $service = new TaskRecurrenceService();

        [$recurrenceA, $daysA] = $service->normalizeTemplateRecurrenceAndDays('daily', []);
        $this->assertSame('daily', $recurrenceA);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $daysA);

        [$recurrenceB, $daysB] = $service->normalizeTemplateRecurrenceAndDays('daily', [1, 3, 5]);
        $this->assertSame('weekly', $recurrenceB);
        $this->assertSame([1, 3, 5], $daysB);

        [$recurrenceC, $daysC] = $service->normalizeTemplateRecurrenceAndDays('weekly', [1, 2, 3, 4, 5, 6, 7]);
        $this->assertSame('daily', $recurrenceC);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $daysC);
    }

    public function test_it_resolves_template_start_and_end_dates_without_http_context(): void
    {
        $service = new TaskRecurrenceService();

        $startDate = $service->resolveTemplateStartDate(
            recurrence: 'weekly',
            rawStartDate: null,
            templateStartDate: null,
            templateCreatedAt: Carbon::parse('2026-03-15 14:30:00'),
        );

        $this->assertSame('2026-03-15', $startDate);
        $this->assertSame('2026-03-30', $service->resolveTemplateEndDate('weekly', '2026-03-30', $startDate));
    }

    public function test_it_rejects_end_date_before_start_date(): void
    {
        $service = new TaskRecurrenceService();

        $this->expectException(ValidationException::class);

        $service->resolveTemplateEndDate('weekly', '2026-03-10', '2026-03-11');
    }

    public function test_it_resolves_alternating_custody_settings_and_inter_household_start(): void
    {
        $service = new TaskRecurrenceService();

        $settings = $service->resolveAlternatingCustodySettings([
            'alternating_custody_enabled' => true,
            'custody_change_day' => 5,
            'custody_home_week_start' => '2026-03-08',
        ], Carbon::parse('2026-03-19'));

        $this->assertTrue($settings['enabled']);
        $this->assertSame(5, $settings['change_day']);
        $this->assertSame('2026-03-06', $settings['home_week_start']);

        $interStart = $service->resolveInterHouseholdWeekStart(true, '2026-03-08', 5, Carbon::parse('2026-03-19'));
        $this->assertSame('2026-03-06', $interStart);
    }

    public function test_it_evaluates_template_applicability_for_inter_household_alternation(): void
    {
        $service = new TaskRecurrenceService();

        $template = new TaskTemplate([
            'recurrence' => 'weekly',
            'recurrence_days' => [1],
            'start_date' => '2026-03-02',
            'end_date' => null,
            'is_inter_household_alternating' => true,
            'inter_household_week_start' => '2026-03-02',
            'is_rotation' => false,
            'assignee_user_ids' => [10],
        ]);

        $this->assertTrue($service->templateAppliesToDate($template, Carbon::parse('2026-03-02'), 1));
        $this->assertFalse($service->templateAppliesToDate($template, Carbon::parse('2026-03-09'), 1));
        $this->assertTrue($service->templateAppliesToDate($template, Carbon::parse('2026-03-16'), 1));
    }
}
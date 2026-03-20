<?php

namespace App\Http\Resources\Tasks;

use App\Models\TaskTemplate;
use App\Support\Normalization;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaskTemplate */
class TaskTemplateResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::toPayload($this->resource);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toPayload(?TaskTemplate $template): array
    {
        if (!$template) {
            return [];
        }

        return [
            'id' => (int) $template->id,
            'name' => (string) $template->name,
            'description' => $template->description,
            'recurrence' => (string) $template->recurrence,
            'start_date' => self::resolveStartDateValue($template),
            'end_date' => optional($template->end_date)->toDateString(),
            'recurrence_days' => self::normalizeRecurrenceDays($template->recurrence_days),
            'assignee_user_ids' => Normalization::memberIds($template->assignee_user_ids),
            'rotation_user_ids' => Normalization::memberIds($template->rotation_user_ids),
            'is_rotation' => (bool) $template->is_rotation,
            'rotation_cycle_weeks' => max(1, min(2, (int) ($template->rotation_cycle_weeks ?? 1))),
            'is_inter_household_alternating' => (bool) ($template->is_inter_household_alternating ?? false),
            'inter_household_week_start' => optional($template->inter_household_week_start)->toDateString(),
            'fixed_user_id' => $template->fixed_user_id ? (int) $template->fixed_user_id : null,
            'fixed_user_name' => $template->fixedUser?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toInstanceTemplatePayload(?TaskTemplate $template): array
    {
        return [
            'id' => (int) ($template?->id ?? 0),
            'recurrence' => (string) ($template?->recurrence ?? 'once'),
            'start_date' => self::resolveStartDateValue($template),
            'end_date' => optional($template?->end_date)->toDateString(),
            'recurrence_days' => self::normalizeRecurrenceDays($template?->recurrence_days),
            'assignee_user_ids' => Normalization::memberIds($template?->assignee_user_ids),
            'rotation_user_ids' => Normalization::memberIds($template?->rotation_user_ids),
            'is_rotation' => (bool) ($template?->is_rotation ?? false),
            'rotation_cycle_weeks' => max(1, min(2, (int) ($template?->rotation_cycle_weeks ?? 1))),
            'is_inter_household_alternating' => (bool) ($template?->is_inter_household_alternating ?? false),
            'inter_household_week_start' => optional($template?->inter_household_week_start)->toDateString(),
        ];
    }

    public static function resolveStartDateValue(?TaskTemplate $template): ?string
    {
        if (!$template) {
            return null;
        }

        if ($template->start_date) {
            return Carbon::parse($template->start_date)->startOfDay()->toDateString();
        }

        if ((string) $template->recurrence !== 'once' && $template->created_at) {
            return Carbon::parse($template->created_at)->startOfDay()->toDateString();
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    public static function normalizeRecurrenceDays(mixed $value): array
    {
        return Normalization::recurrenceDays($value);
    }
}

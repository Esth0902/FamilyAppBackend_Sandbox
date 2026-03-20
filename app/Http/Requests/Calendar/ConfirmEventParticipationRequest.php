<?php

namespace App\Http\Requests\Calendar;

use App\Models\Event;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ConfirmEventParticipationRequest extends CalendarContextRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $household = $this->household();
        if (!$this->isCalendarModuleEnabled($household)) {
            throw new AuthorizationException('Le module calendrier est désactivé pour ce foyer.');
        }

        $event = $this->route('event');
        if (!$event instanceof Event || !$this->eventBelongsToHousehold($event, $household)) {
            throw new NotFoundHttpException('Événement introuvable.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:participate,not_participate'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}

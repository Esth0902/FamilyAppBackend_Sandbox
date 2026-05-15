<?php

namespace App\Http\Requests\Calendar;

use App\Models\Event;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DestroyEventRequest extends CalendarContextRequest
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

        if (!$this->canManageEvent($event, (int) $this->user()->id, $this->householdRole())) {
            throw new AuthorizationException('Tu peux modifier uniquement tes événements.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}

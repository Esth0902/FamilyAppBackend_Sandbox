<?php

namespace App\Http\Requests\Calendar;

use App\Models\Event;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Validator;
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

        if (!$this->isCurrentUserInvitedToEvent($event)) {
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $event = $this->route('event');
            if (!$event instanceof Event) {
                return;
            }

            if ((bool) ($event->response_required ?? true)) {
                return;
            }

            $validator->errors()->add(
                'status',
                'Cet événement est en mode information. Aucune réponse n est attendue.'
            );
        });
    }

    private function isCurrentUserInvitedToEvent(Event $event): bool
    {
        $audienceMode = Event::normalizeAudienceMode((string) $event->audience_mode);
        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            return true;
        }

        return $event->invitations()
            ->where('household_id', (int) $this->household()->id)
            ->where('user_id', (int) $this->user()->id)
            ->exists();
    }
}


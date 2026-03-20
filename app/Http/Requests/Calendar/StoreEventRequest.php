<?php

namespace App\Http\Requests\Calendar;

use App\Models\Event;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreEventRequest extends CalendarContextRequest
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
        if ($event instanceof Event) {
            if (!$this->eventBelongsToHousehold($event, $household)) {
                throw new NotFoundHttpException('Événement introuvable.');
            }

            if (!$this->canManageEvent($event, (int) $this->user()->id, $this->householdRole())) {
                throw new AuthorizationException('Vous pouvez modifier uniquement vos événements.');
            }
        }

        if ($this->boolean('is_shared_with_other_household') && $this->householdRole() !== User::ROLE_PARENT) {
            throw new AuthorizationException('Seul un parent peut partager un événement avec un autre foyer.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after_or_equal:start_at'],
            'is_shared_with_other_household' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || !$this->boolean('is_shared_with_other_household')) {
                return;
            }

            $calendarSettings = $this->resolveCalendarSettings($this->household());
            if (!($calendarSettings['shared_view_enabled'] ?? true)) {
                $validator->errors()->add(
                    'is_shared_with_other_household',
                    'Le partage inter-foyers est désactivé pour ce foyer.'
                );
            }

            if (!$this->hasConnectedHousehold($this->household())) {
                $validator->errors()->add(
                    'is_shared_with_other_household',
                    'Aucun foyer connecté n est disponible pour le partage.'
                );
            }
        });
    }
}

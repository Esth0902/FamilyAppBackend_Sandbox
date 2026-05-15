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
                throw new AuthorizationException('Tu peux modifier uniquement tes événements.');
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
            'audience_mode' => ['nullable', 'string', 'in:all_members,only_me,selected_members'],
            'invited_user_ids' => ['nullable', 'array'],
            'invited_user_ids.*' => ['integer', 'distinct'],
            'response_required' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->boolean('is_shared_with_other_household')) {
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
                        'Aucun foyer connecté n\'est disponible pour le partage.'
                    );
                }
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $audienceMode = $this->resolveRequestedAudienceMode();
            $invitedUserIds = $this->resolveRequestedInvitedUserIds();
            $currentUserId = (int) $this->user()->id;

            if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS && count($invitedUserIds) > 0) {
                $validator->errors()->add(
                    'invited_user_ids',
                    'Aucun invité spécifique n est attendu pour un événement public au foyer.'
                );
            }

            if (
                $audienceMode === Event::AUDIENCE_ONLY_ME
                && count($invitedUserIds) > 0
                && $invitedUserIds !== [$currentUserId]
            ) {
                $validator->errors()->add(
                    'invited_user_ids',
                    'Le mode only_me ne peut contenir que ton identifiant.'
                );
            }

            if ($audienceMode === Event::AUDIENCE_SELECTED_MEMBERS && count($invitedUserIds) === 0) {
                $validator->errors()->add(
                    'invited_user_ids',
                    'Sélectionnez au moins un membre du foyer pour ce mode d\'audience.'
                );
            }

            if (
                $audienceMode !== Event::AUDIENCE_SELECTED_MEMBERS
                || count($invitedUserIds) === 0
            ) {
                return;
            }

            $householdUserIds = $this->household()->users()
                ->pluck('users.id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();

            foreach ($invitedUserIds as $invitedUserId) {
                if (!in_array($invitedUserId, $householdUserIds, true)) {
                    $validator->errors()->add(
                        'invited_user_ids',
                        'Tous les invités doivent appartenir au foyer actif.'
                    );
                    break;
                }
            }
        });
    }

    public function audienceMode(): string
    {
        return Event::normalizeAudienceMode(
            (string) $this->validated('audience_mode', Event::AUDIENCE_ALL_MEMBERS)
        );
    }

    /**
     * @return array<int, int>
     */
    public function invitedUserIds(): array
    {
        $audienceMode = $this->audienceMode();
        if ($audienceMode === Event::AUDIENCE_ALL_MEMBERS) {
            return [];
        }

        if ($audienceMode === Event::AUDIENCE_ONLY_ME) {
            return [(int) $this->user()->id];
        }

        return collect($this->validated('invited_user_ids', []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function responseRequired(): bool
    {
        return (bool) $this->validated('response_required', true);
    }

    private function resolveRequestedAudienceMode(): string
    {
        return Event::normalizeAudienceMode((string) $this->input('audience_mode', Event::AUDIENCE_ALL_MEMBERS));
    }

    /**
     * @return array<int, int>
     */
    private function resolveRequestedInvitedUserIds(): array
    {
        return collect($this->input('invited_user_ids', []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}


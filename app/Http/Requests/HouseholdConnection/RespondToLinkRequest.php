<?php

namespace App\Http\Requests\HouseholdConnection;

use App\Models\HouseholdLinkRequest;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class RespondToLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $notification = $this->notification();
        if (!$user instanceof User || !$notification instanceof UserNotification) {
            return false;
        }

        if ((int) $notification->user_id !== (int) $user->id) {
            return false;
        }

        if ((string) $notification->type !== 'household_link_request') {
            return false;
        }

        $linkRequestId = (int) data_get($notification->data, 'link_request_id', 0);
        if ($linkRequestId <= 0) {
            return false;
        }

        $linkRequest = HouseholdLinkRequest::query()
            ->whereKey($linkRequestId)
            ->where('status', 'pending')
            ->first();

        if (!$linkRequest instanceof HouseholdLinkRequest) {
            return false;
        }

        return $user->households()
            ->where('households.id', (int) $linkRequest->to_household_id)
            ->wherePivot('role', User::ROLE_PARENT)
            ->exists();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:accept,refuse,reject'],
        ];
    }

    public function notification(): ?UserNotification
    {
        $notification = $this->route('notification');
        return $notification instanceof UserNotification ? $notification : null;
    }

    public function actorOrFail(): User
    {
        $user = $this->user();
        if ($user instanceof User) {
            return $user;
        }

        throw ValidationException::withMessages([
            'user' => ['Utilisateur authentifie introuvable.'],
        ]);
    }

    public function actionValue(): string
    {
        $action = (string) $this->validated('action');
        return $action === 'reject' ? 'refuse' : $action;
    }
}

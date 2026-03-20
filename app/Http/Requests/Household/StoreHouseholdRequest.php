<?php

namespace App\Http\Requests\Household;

use App\Http\Requests\Household\Concerns\HasHouseholdConfigurationRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreHouseholdRequest extends FormRequest
{
    use HasHouseholdConfigurationRules;

    public function authorize(): bool
    {
        return true;
    }

    public function actor(): ?User
    {
        $user = $this->user();
        return $user instanceof User ? $user : null;
    }

    public function actorOrFail(): User
    {
        $actor = $this->actor();
        if ($actor instanceof User) {
            return $actor;
        }

        throw ValidationException::withMessages([
            'user' => ['Utilisateur authentifié introuvable.'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();

        if (!isset($payload['members']) || !is_array($payload['members'])) {
            return;
        }

        foreach ($payload['members'] as $index => $memberPayload) {
            if (!is_array($memberPayload) || !array_key_exists('email', $memberPayload)) {
                continue;
            }

            $payload['members'][$index]['email'] = $this->normalizeEmailInput($memberPayload['email']);
        }

        $this->replace($payload);
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return array_merge($this->householdConfigurationRules(false), [
            'members' => 'nullable|array',
            'members.*.name' => 'required|string|max:255',
            'members.*.role' => 'required|in:parent,enfant',
            'members.*.email' => 'nullable|email',
            'members.*.budget' => 'nullable|array',
            'members.*.budget.base_amount' => 'nullable|numeric|min:0',
            'members.*.budget.recurrence' => 'nullable|in:weekly,monthly',
            'members.*.budget.reset_day' => 'nullable|integer|min:1|max:31',
            'members.*.budget.allow_advances' => 'nullable|boolean',
            'members.*.budget.max_advance_amount' => 'nullable|numeric|min:0',

            // Compatibilité temporaire avec l'ancien setup mobile.
            'children_profiles' => 'nullable|array',
            'children_profiles.*' => 'nullable|string|max:255',
            'settings' => 'nullable|array',
            'poll_day' => 'nullable',
            'poll_time' => 'nullable|string',
            'poll_duration' => 'nullable|integer|min:1|max:168',
        ]);
    }

    private function normalizeEmailInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        return $normalized !== '' ? $normalized : null;
    }
}

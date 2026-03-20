<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class AddHouseholdMemberRequest extends FormRequest
{
    private ?Household $resolvedHousehold = null;

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

    public function authorize(): bool
    {
        $user = $this->actor();
        if (!$user instanceof User) {
            return false;
        }

        $requestedHouseholdId = $this->resolveRequestedHouseholdId();
        if ($requestedHouseholdId !== null) {
            $household = $user->households()
                ->where('households.id', $requestedHouseholdId)
                ->first();

            if (!$household) {
                throw ValidationException::withMessages([
                    'household' => ['Foyer non accessible pour cet utilisateur.'],
                ]);
            }

            $this->resolvedHousehold = $household;
            return (string) ($household->pivot->role ?? User::ROLE_CHILD) === User::ROLE_PARENT;
        }

        $household = $user->households()->first();
        if (!$household) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associé à cet utilisateur.'],
            ]);
        }

        $this->resolvedHousehold = $household;
        return (string) ($household->pivot->role ?? User::ROLE_CHILD) === User::ROLE_PARENT;
    }

    protected function prepareForValidation(): void
    {
        if (!$this->exists('email')) {
            return;
        }

        $this->merge([
            'email' => $this->normalizeEmailInput($this->input('email')),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'role' => 'required|in:parent,enfant',
        ];
    }

    public function household(): Household
    {
        if ($this->resolvedHousehold) {
            return $this->resolvedHousehold;
        }

        $user = $this->user();
        if (!$user) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associé à cet utilisateur.'],
            ]);
        }

        $household = $user->households()->first();
        if (!$household) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associé à cet utilisateur.'],
            ]);
        }

        $this->resolvedHousehold = $household;

        return $household;
    }

    private function resolveRequestedHouseholdId(): ?int
    {
        $rawValue = $this->header('X-Household-Id');
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return null;
        }

        $parsed = (int) $rawValue;
        return $parsed > 0 ? $parsed : null;
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

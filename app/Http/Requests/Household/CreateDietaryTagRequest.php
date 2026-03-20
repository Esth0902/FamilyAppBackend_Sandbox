<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class CreateDietaryTagRequest extends FormRequest
{
    /**
     * @var array<int, string>
     */
    private const DIETARY_TAG_TYPES = ['diet', 'allergen', 'dislike', 'restriction', 'cuisine_rule'];

    private ?Household $resolvedHousehold = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $household = $this->resolveParentHouseholdFor($user);
        return (string) ($household->pivot->role ?? User::ROLE_CHILD) === User::ROLE_PARENT;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'label' => 'required|string|min:2|max:120',
            'type' => 'required|string|in:' . implode(',', self::DIETARY_TAG_TYPES),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $label = trim((string) $this->input('label'));
            if ($label !== '' && Str::slug($label) === '') {
                $validator->errors()->add('label', 'Le tag est invalide.');
            }
        });
    }

    public function household(): Household
    {
        if ($this->resolvedHousehold) {
            return $this->resolvedHousehold;
        }

        $user = $this->user();
        if (!$user instanceof User) {
            throw ValidationException::withMessages([
                'household' => ['Aucun foyer associé à cet utilisateur.'],
            ]);
        }

        $household = $this->resolveParentHouseholdFor($user);
        if ((string) ($household->pivot->role ?? User::ROLE_CHILD) !== User::ROLE_PARENT) {
            throw ValidationException::withMessages([
                'household' => ['Action réservée aux parents.'],
            ]);
        }

        return $household;
    }

    private function resolveParentHouseholdFor(User $user): Household
    {
        if ($this->resolvedHousehold) {
            return $this->resolvedHousehold;
        }

        $requestedHouseholdId = $this->resolveRequestedHouseholdId();
        $household = $requestedHouseholdId !== null
            ? $user->households()->where('households.id', $requestedHouseholdId)->first()
            : $user->households()->first();

        if (!$household) {
            throw ValidationException::withMessages([
                'household' => ['Foyer non accessible pour cet utilisateur.'],
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
}

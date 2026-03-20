<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UpdateHouseholdMemberRequest extends FormRequest
{
    private ?Household $resolvedHousehold = null;

    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user instanceof User) {
            return false;
        }

        $household = $this->resolveParentHouseholdFor($user);
        if ((string) ($household->pivot->role ?? User::ROLE_CHILD) !== User::ROLE_PARENT) {
            return false;
        }

        $member = $this->route('member');
        if (!$member instanceof User) {
            return false;
        }

        $memberInHousehold = $household->users()
            ->where('users.id', $member->id)
            ->first();
        if (!$memberInHousehold) {
            throw new NotFoundHttpException('Membre introuvable pour ce foyer.');
        }

        $requestedRole = $this->input('role');
        $currentRole = (string) ($memberInHousehold->pivot->role ?? User::ROLE_CHILD);

        if (
            $currentRole === User::ROLE_PARENT
            && $requestedRole === User::ROLE_CHILD
            && !$this->hasOtherParent($household, (int) $member->id)
        ) {
            throw ValidationException::withMessages([
                'role' => ['Le foyer doit conserver au moins un parent. Désignez un nouveau parent ou supprimez le foyer.'],
            ]);
        }

        return true;
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
        /** @var User|null $member */
        $member = $this->route('member');
        $memberId = $member instanceof User ? (int) $member->id : 0;

        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:users,email,' . $memberId,
            'role' => 'sometimes|in:parent,enfant',
            'nickname' => 'sometimes|nullable|string|max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Cet e-mail est déjà utilisé.',
        ];
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

    private function hasOtherParent(Household $household, int $memberId): bool
    {
        return $household->users()
            ->wherePivot('role', User::ROLE_PARENT)
            ->where('users.id', '!=', $memberId)
            ->exists();
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

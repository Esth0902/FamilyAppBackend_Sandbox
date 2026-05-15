<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DeleteHouseholdMemberRequest extends FormRequest
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

        if ((int) $user->id === (int) $member->id) {
            throw ValidationException::withMessages([
                'member' => ['Tu ne peux pas te supprimer toi-même du foyer.'],
            ]);
        }

        $memberRole = (string) ($memberInHousehold->pivot->role ?? User::ROLE_CHILD);
        if ($memberRole === User::ROLE_PARENT && !$this->hasOtherParent($household, (int) $member->id)) {
            throw ValidationException::withMessages([
                'role' => ['Le foyer doit conserver au moins un parent. Désigne un nouveau parent ou supprime le foyer.'],
            ]);
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [];
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
}

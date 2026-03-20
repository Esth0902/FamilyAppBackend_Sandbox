<?php

namespace App\Http\Requests\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RefreshMemberAccessRequest extends FormRequest
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

        if (!(bool) $member->must_change_password) {
            throw ValidationException::withMessages([
                'member' => ['Ce membre a déjà changé son mot de passe.'],
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

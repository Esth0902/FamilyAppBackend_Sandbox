<?php

namespace App\Http\Requests\HouseholdConnection;

use App\Models\HouseholdLinkCode;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;

class SubmitLinkRequest extends HouseholdConnectionRequest
{
    public function authorize(): bool
    {
        $user = $this->actor();
        if (!$user instanceof User) {
            return false;
        }

        return $this->isParentRole();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:8', 'alpha_num'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $normalizedCode = $this->normalizedCode();
            if ($normalizedCode === '') {
                $validator->errors()->add('code', 'Le code de liaison est invalide.');
                return;
            }

            $code = HouseholdLinkCode::query()
                ->where('code', $normalizedCode)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first();

            if (!$code instanceof HouseholdLinkCode) {
                $validator->errors()->add('code', 'Ce code de liaison est invalide ou expire.');
                return;
            }

            if ((int) $code->household_id === (int) $this->household()->id) {
                $validator->errors()->add('code', 'Ce code appartient deja a votre foyer.');
            }
        });
    }

    public function normalizedCode(): string
    {
        $code = (string) $this->validated('code', '');
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', trim($code)));
    }
}


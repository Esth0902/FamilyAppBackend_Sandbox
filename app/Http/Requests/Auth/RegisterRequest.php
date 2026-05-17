<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'accept_legal_terms' => ['accepted'],
            'cgu_version' => ['required', 'string', 'max:50'],
            'privacy_policy_version' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => "Cet e-mail est déjà utilisé.",
            'accept_legal_terms.accepted' => "L'acceptation des conditions est obligatoire.",
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->normalizeEmailInput($this->input('email')),
            'cgu_version' => $this->normalizeVersionInput($this->input('cgu_version')),
            'privacy_policy_version' => $this->normalizeVersionInput($this->input('privacy_policy_version')),
        ]);
    }

    private function normalizeEmailInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeVersionInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        return $normalized !== '' ? $normalized : null;
    }
}

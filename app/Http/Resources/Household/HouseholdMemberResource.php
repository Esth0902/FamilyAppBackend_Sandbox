<?php

namespace App\Http\Resources\Household;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class HouseholdMemberResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'email' => (string) $this->email,
            'must_change_password' => (bool) $this->must_change_password,
            'role' => (string) ($this->pivot->role ?? User::ROLE_CHILD),
            'nickname' => (string) ($this->pivot->nickname ?? $this->name),
        ];
    }

    /**
     * @return array{generated_email:string,generated_password:string,share_text:string}
     */
    public static function temporaryAccessMeta(User $member, string $generatedPassword): array
    {
        return [
            'generated_email' => (string) $member->email,
            'generated_password' => $generatedPassword,
            'share_text' => self::buildTemporaryAccessShareText(
                (string) $member->name,
                (string) $member->email,
                $generatedPassword
            ),
        ];
    }

    public static function buildTemporaryAccessShareText(string $name, string $email, string $generatedPassword): string
    {
        return "Bonjour {$name} !\n\n"
            . "Ton compte FamilyFlow est prêt.\n"
            . "Connecte-toi avec les identifiants suivants :\n"
            . "E-mail : {$email}\n"
            . "Mot de passe temporaire : {$generatedPassword}\n\n"
            . "N'oublie pas de modifier ton mot de passe dès la première connexion.";
    }
}


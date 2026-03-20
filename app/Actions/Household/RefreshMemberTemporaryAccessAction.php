<?php

namespace App\Actions\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RefreshMemberTemporaryAccessAction
{
    /**
     * @return array{
     *     member: User,
     *     generated_email: string,
     *     generated_password: string,
     *     share_text: string
     * }
     */
    public function execute(Household $household, User $member): array
    {
        $rawPassword = Str::random(10);

        $member->forceFill([
            'password' => $rawPassword,
            'must_change_password' => true,
        ])->save();

        /** @var User $freshMember */
        $freshMember = $household->users()
            ->where('users.id', $member->id)
            ->firstOrFail();

        $shareText = $this->buildMemberShareText(
            (string) $freshMember->name,
            (string) $freshMember->email,
            $rawPassword
        );

        $this->sendTemporaryAccessEmail(
            (string) $freshMember->email,
            (string) $freshMember->name,
            $shareText
        );

        return [
            'member' => $freshMember,
            'generated_email' => (string) $freshMember->email,
            'generated_password' => $rawPassword,
            'share_text' => $shareText,
        ];
    }

    private function sendTemporaryAccessEmail(string $email, string $name, string $shareText): void
    {
        if (trim($email) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::raw($shareText, function ($message) use ($email, $name): void {
                if ($name !== '') {
                    $message->to($email, $name);
                } else {
                    $message->to($email);
                }

                $message->subject('Accès temporaire FamilyFlow');
            });
        } catch (\Throwable $exception) {
            Log::warning('Temporary access email failed: ' . $exception->getMessage());
        }
    }

    private function buildMemberShareText(string $name, string $email, string $rawPassword): string
    {
        return "Bonjour {$name} !\n\n"
            . "Ton compte FamilyFlow est prêt.\n"
            . "Connecte-toi avec les identifiants suivants :\n"
            . "E-mail : {$email}\n"
            . "Mot de passe temporaire : {$rawPassword}\n\n"
            . "N'oublie pas de modifier ton mot de passe dès la première connexion.";
    }
}

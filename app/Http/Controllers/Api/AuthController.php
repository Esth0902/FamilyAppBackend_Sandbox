<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BudgetSetting;
use App\Models\Household;
use App\Models\User;
use App\Support\JsonUtf8Sanitizer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $normalizedEmail = (string) $credentials['email'];

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->with('households')
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants sont incorrects.'],
            ]);
        }

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'user' => $user,
            'token' => $token,
        ]));
    }

    public function me(Request $request)
    {
        return response()->json(JsonUtf8Sanitizer::sanitize([
            'user' => $request->user()->load('households'),
        ]));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnecté',
        ]);
    }

    public function register(Request $request)
    {
        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'email.unique' => "Cet e-mail est déjà utilisé.",
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'user' => $user,
            'token' => $token,
        ]), 201);
    }

    public function forgotPassword(Request $request)
    {
        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
        ]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        // Response is intentionally generic to avoid leaking whether the email exists.
        PasswordFacade::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'Si un compte existe pour cet e-mail, un lien de réinitialisation a été envoyé.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
        ]);

        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $status = PasswordFacade::reset(
            [
                'token' => (string) $validated['token'],
                'email' => (string) $validated['email'],
                'password' => (string) $validated['password'],
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ],
            function (User $user) use ($validated): void {
                $user->forceFill([
                    'password' => (string) $validated['password'],
                    'remember_token' => Str::random(60),
                    'must_change_password' => false,
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== PasswordFacade::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Mot de passe reinitialise avec succes.',
        ]);
    }

    public function changeInitialCredentials(Request $request)
    {
        $user = $request->user();
        $request->merge([
            'email' => $this->normalizeEmailInput($request->input('email')),
        ]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'email.unique' => "Cet e-mail est déjà utilisé.",
        ]);

        $user->forceFill([
            'email' => $validated['email'],
            'password' => $validated['password'],
            'must_change_password' => false,
        ])->save();

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'message' => 'Identifiants mis a jour.',
            'user' => $user->fresh()->load('households'),
        ]));
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        if ($request->exists('email')) {
            $request->merge([
                'email' => $this->normalizeEmailInput($request->input('email')),
            ]);
        }

        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'current_password' => ['required_with:email,password', 'string'],
        ], [
            'email.unique' => "Cet e-mail est déjà utilisé.",
        ]);

        $emailWasProvided = array_key_exists('email', $validated) && $validated['email'] !== null;
        $passwordWasProvided = array_key_exists('password', $validated);

        if (! $emailWasProvided && ! $passwordWasProvided) {
            throw ValidationException::withMessages([
                'profile' => ['Aucune modification demandee.'],
            ]);
        }

        if (! Hash::check((string) $validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $updates = [];
        if ($emailWasProvided) {
            $updates['email'] = (string) $validated['email'];
        }
        if ($passwordWasProvided) {
            $updates['password'] = (string) $validated['password'];
        }

        if (! empty($updates)) {
            $user->forceFill($updates)->save();
        }

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'message' => 'Profil mis a jour.',
            'user' => $user->fresh()->load('households'),
        ]));
    }

    public function destroyAccount(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        if (!Hash::check((string) $validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Le mot de passe actuel est incorrect.'],
            ]);
        }

        $blockedHouseholds = $this->resolveSoleParentBlockingHouseholds($user);
        if (count($blockedHouseholds) > 0) {
            return response()->json(JsonUtf8Sanitizer::sanitize([
                'message' => "Vous êtes le dernier parent d'au moins un foyer. Désignez un nouveau parent ou supprimez le foyer concerné avant de supprimer votre compte.",
                'required_action' => 'define_new_parent_or_delete_household',
                'blocked_households' => $blockedHouseholds,
            ]), 422);
        }

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->households()->detach();

            BudgetSetting::query()
                ->where('user_id', (int) $user->id)
                ->delete();

            $user->delete();
        });

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'message' => 'Compte utilisateur supprimé définitivement.',
        ]));
    }

    public function updateHouseholdNickname(Request $request, Household $household)
    {
        $validated = $request->validate([
            'nickname' => ['required', 'string', 'max:255'],
        ]);
        $nickname = trim((string) $validated['nickname']);

        if ($nickname === '') {
            throw ValidationException::withMessages([
                'nickname' => ['Le pseudo ne peut pas etre vide.'],
            ]);
        }

        $user = $request->user();

        $membership = $user->households()
            ->where('households.id', $household->id)
            ->first();

        if (! $membership) {
            return response()->json([
                'message' => 'Foyer non accessible.',
            ], 403);
        }

        $user->households()->updateExistingPivot($household->id, [
            'nickname' => $nickname,
        ]);

        $updatedMembership = $user->households()
            ->where('households.id', $household->id)
            ->firstOrFail();

        return response()->json(JsonUtf8Sanitizer::sanitize([
            'message' => 'Pseudo du foyer mis a jour.',
            'household' => [
                'id' => $updatedMembership->id,
                'name' => $updatedMembership->name,
                'role' => $updatedMembership->pivot->role,
                'nickname' => $updatedMembership->pivot->nickname,
            ],
        ]));
    }

    private function normalizeEmailInput(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array<int, array{
     *     household: array{id:int, name:string},
     *     candidate_members: array<int, array{id:int, name:string, role:string}>
     * }>
     */
    private function resolveSoleParentBlockingHouseholds(User $user): array
    {
        $parentHouseholds = $user->households()
            ->wherePivot('role', User::ROLE_PARENT)
            ->get(['households.id', 'households.name']);

        $blocked = [];

        /** @var Household $household */
        foreach ($parentHouseholds as $household) {
            $hasOtherParent = $household->users()
                ->wherePivot('role', User::ROLE_PARENT)
                ->where('users.id', '!=', (int) $user->id)
                ->exists();

            if ($hasOtherParent) {
                continue;
            }

            $candidateMembers = $household->users()
                ->select(['users.id', 'users.name'])
                ->where('users.id', '!=', (int) $user->id)
                ->orderByRaw("CASE WHEN household_user.role = ? THEN 0 ELSE 1 END", [User::ROLE_PARENT])
                ->orderBy('users.name')
                ->get()
                ->map(static fn(User $member): array => [
                    'id' => (int) $member->id,
                    'name' => (string) $member->name,
                    'role' => (string) ($member->pivot->role ?? User::ROLE_CHILD),
                ])
                ->values()
                ->all();

            $blocked[] = [
                'household' => [
                    'id' => (int) $household->id,
                    'name' => (string) $household->name,
                ],
                'candidate_members' => $candidateMembers,
            ];
        }

        return $blocked;
    }
}

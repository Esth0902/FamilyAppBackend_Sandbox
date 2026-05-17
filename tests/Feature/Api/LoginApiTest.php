<?php

namespace Tests\Feature\Api;

use App\Models\Household;
use App\Models\User;
use App\Models\UserLegalAcceptance;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LoginApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_is_case_insensitive_for_email(): void
    {
        $email = 'nathan.case.' . uniqid() . '@test.com';

        $user = User::factory()->create([
            'email' => $email,
        ]);

        $this->postJson('/api/login', [
            'email' => strtoupper($email),
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure([
                'user',
                'access_token',
                'token_type',
            ]);
    }

    public function test_register_normalizes_email_to_lowercase(): void
    {
        $email = 'NaThan.Upper.' . uniqid() . '@Test.COM';

        $response = $this->postJson('/api/register', [
            'name' => 'Nathan',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_legal_terms' => true,
            'cgu_version' => '2026-05-17',
            'privacy_policy_version' => '2026-05-17',
        ])
            ->assertCreated()
            ->assertJsonStructure([
                'user',
                'access_token',
                'token_type',
            ])
            ->assertJsonPath('token_type', 'Bearer');

        $userId = (int) $response->json('user.id');
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => mb_strtolower($email),
        ]);

        $this->assertDatabaseHas('user_legal_acceptances', [
            'user_id' => $userId,
            'document_type' => UserLegalAcceptance::DOCUMENT_CGU,
            'document_version' => '2026-05-17',
        ]);

        $this->assertDatabaseHas('user_legal_acceptances', [
            'user_id' => $userId,
            'document_type' => UserLegalAcceptance::DOCUMENT_PRIVACY_POLICY,
            'document_version' => '2026-05-17',
        ]);
    }

    public function test_register_rejects_duplicate_email_with_different_case(): void
    {
        $email = 'nathan.duplicate.' . uniqid() . '@test.com';
        User::factory()->create([
            'email' => $email,
        ]);

        $this->postJson('/api/register', [
            'name' => 'Nathan Bis',
            'email' => strtoupper($email),
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_legal_terms' => true,
            'cgu_version' => '2026-05-17',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Cet e-mail est déjà utilisé.');
    }

    public function test_register_requires_legal_terms_acceptance(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Nathan Legal',
            'email' => 'nathan.legal.' . uniqid() . '@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_legal_terms' => false,
            'cgu_version' => '2026-05-17',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accept_legal_terms']);
    }

    public function test_login_response_contains_household_context_when_user_has_membership(): void
    {
        $user = User::factory()->create([
            'email' => 'household.context.' . uniqid() . '@test.com',
        ]);

        $household = Household::query()->create([
            'name' => 'Foyer contexte',
            'is_setup_completed' => true,
        ]);

        $household->users()->attach((int) $user->id, [
            'role' => User::ROLE_PARENT,
            'nickname' => 'Parent principal',
        ]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', (int) $user->id)
            ->assertJsonPath('user.household_id', (int) $household->id)
            ->assertJsonPath('user.households.0.id', (int) $household->id)
            ->assertJsonPath('user.households.0.pivot.role', User::ROLE_PARENT);
    }

    public function test_change_initial_credentials_records_legal_acceptance(): void
    {
        $email = 'must.change.' . uniqid() . '@test.com';
        $user = User::factory()->create([
            'email' => $email,
            'must_change_password' => true,
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();

        $token = (string) $loginResponse->json('access_token');

        $this->withToken($token)->postJson('/api/auth/change-initial-credentials', [
            'email' => 'new.' . $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'accept_legal_terms' => true,
            'cgu_version' => '2026-05-17',
            'privacy_policy_version' => '2026-05-17',
        ])->assertOk();

        $this->assertDatabaseHas('user_legal_acceptances', [
            'user_id' => (int) $user->id,
            'document_type' => UserLegalAcceptance::DOCUMENT_CGU,
            'document_version' => '2026-05-17',
        ]);
    }

    public function test_outdated_legal_acceptance_blocks_protected_requests_with_specific_error(): void
    {
        $email = 'legal.outdated.' . uniqid() . '@test.com';
        $user = User::factory()->create([
            'email' => $email,
            'accepted_cgu_version' => '2026-01-01',
            'accepted_privacy_policy_version' => '2026-01-01',
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();

        $token = (string) $loginResponse->json('access_token');

        $this->withToken($token)->getJson('/api/me')
            ->assertStatus(403)
            ->assertJsonPath('error', 'cgu_update_required')
            ->assertJsonPath('latest_version', '2026-05-17');
    }

    public function test_user_can_accept_latest_legal_documents_and_resume_access(): void
    {
        $email = 'legal.accept.' . uniqid() . '@test.com';
        $user = User::factory()->create([
            'email' => $email,
            'accepted_cgu_version' => '2026-01-01',
            'accepted_privacy_policy_version' => '2026-01-01',
        ]);

        $loginResponse = $this->postJson('/api/login', [
            'email' => $email,
            'password' => 'password',
        ])->assertOk();

        $token = (string) $loginResponse->json('access_token');

        $this->withToken($token)->postJson('/api/auth/accept-legal-documents', [
            'accept_legal_terms' => true,
            'cgu_version' => '2026-05-17',
            'privacy_policy_version' => '2026-05-17',
        ])->assertOk();

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', (int) $user->id);
    }
}

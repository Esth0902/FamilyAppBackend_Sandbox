<?php

namespace Tests\Feature\Api;

use App\Models\User;
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
                'token',
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
        ])->assertCreated();

        $userId = (int) $response->json('user.id');
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => mb_strtolower($email),
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
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', "Cet e-mail est déjà utilisé.");
    }
}

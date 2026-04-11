<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('register creates a user and returns a token', function () {
    $response = $this->postJson('/api/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'faculty',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'user' => ['id', 'first_name', 'last_name', 'email', 'role'],
            'token',
            'token_type',
        ])
        ->assertJsonPath('user.email', 'test@example.com')
        ->assertJsonPath('user.first_name', 'Test')
        ->assertJsonPath('user.last_name', 'User')
        ->assertJsonPath('user.role', 'faculty')
        ->assertJsonPath('token_type', 'Bearer');

    expect(User::where('email', 'test@example.com')->exists())->toBeTrue();
});

test('login returns a token for valid credentials', function () {
    User::factory()->create([
        'email' => 'faculty@example.com',
        'password' => Hash::make('secret-pass'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'faculty@example.com',
        'password' => 'secret-pass',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'first_name', 'last_name', 'email', 'role'],
            'token',
            'token_type',
        ]);
});

test('register accepts camelCase firstName and lastName', function () {
    $response = $this->postJson('/api/register', [
        'firstName' => 'Jane',
        'lastName' => 'Doe',
        'email' => 'jane@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'student',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.first_name', 'Jane')
        ->assertJsonPath('user.last_name', 'Doe')
        ->assertJsonPath('user.role', 'student');
});

test('login rejects invalid credentials', function () {
    User::factory()->create([
        'email' => 'faculty@example.com',
        'password' => Hash::make('secret-pass'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'faculty@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'The provided credentials are incorrect.');
});

test('logout revokes the current token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('auth-token')->plainTextToken;

    expect(DB::table('personal_access_tokens')->count())->toBe(1);

    $response = $this->postJson('/api/logout', [], [
        'Authorization' => 'Bearer '.$token,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Logged out successfully.');

    expect(DB::table('personal_access_tokens')->count())->toBe(0);

    // Multiple HTTP calls in one test reuse the resolved Sanctum guard; clear it so the
    // second request re-resolves auth from the (revoked) bearer token like production.
    Auth::forgetGuards();

    $this->postJson('/api/logout', [], [
        'Authorization' => 'Bearer '.$token,
    ])->assertUnauthorized();
});

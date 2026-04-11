<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('register creates a user and returns a token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'email'],
            'token',
            'token_type',
        ])
        ->assertJsonPath('user.email', 'test@example.com')
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
            'user' => ['id', 'name', 'email'],
            'token',
            'token_type',
        ]);
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

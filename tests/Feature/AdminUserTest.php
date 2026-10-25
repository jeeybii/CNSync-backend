<?php

use App\Enums\UserRole;
use App\Models\User;

function adminAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('admin can update a non-admin user profile', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $user = User::factory()->create([
        'first_name' => 'Old',
        'last_name' => 'Name',
        'email' => 'old@example.com',
        'role' => UserRole::Student,
    ]);

    $this->patchJson("/api/admin/users/{$user->id}", [
        'first_name' => 'Updated',
        'last_name' => 'User',
        'email' => 'updated@example.com',
        'role' => 'faculty',
    ], adminAuthHeader($admin))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.first_name', 'Updated')
        ->assertJsonPath('data.last_name', 'User')
        ->assertJsonPath('data.email', 'updated@example.com')
        ->assertJsonPath('data.role', 'faculty');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'first_name' => 'Updated',
        'last_name' => 'User',
        'email' => 'updated@example.com',
        'role' => 'faculty',
    ]);
});

test('admin can update an admin user profile', function () {
    $admin = User::factory()->create([
        'first_name' => 'Original',
        'last_name' => 'Admin',
        'email' => 'admin@example.com',
        'role' => UserRole::Admin,
    ]);

    $this->patchJson("/api/admin/users/{$admin->id}", [
        'first_name' => 'Updated',
        'last_name' => 'Administrator',
        'email' => 'updated-admin@example.com',
        'role' => 'admin',
    ], adminAuthHeader($admin))
        ->assertOk()
        ->assertJsonPath('data.id', $admin->id)
        ->assertJsonPath('data.first_name', 'Updated')
        ->assertJsonPath('data.last_name', 'Administrator')
        ->assertJsonPath('data.email', 'updated-admin@example.com')
        ->assertJsonPath('data.role', 'admin');

    $this->assertDatabaseHas('users', [
        'id' => $admin->id,
        'first_name' => 'Updated',
        'last_name' => 'Administrator',
        'email' => 'updated-admin@example.com',
        'role' => 'admin',
    ]);
});

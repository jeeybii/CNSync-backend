<?php

use App\Models\Project;
use App\Models\User;

function authHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('student cannot list projects', function () {
    $user = User::factory()->student()->create();

    $this->getJson('/api/projects', authHeader($user))
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is restricted to faculty accounts.');
});

test('faculty can create and list projects', function () {
    $user = User::factory()->create();

    $this->postJson('/api/projects', [
        'title' => 'CS 101 Spring',
        'description' => 'Intro course',
    ], authHeader($user))->assertCreated()
        ->assertJsonPath('data.title', 'CS 101 Spring')
        ->assertJsonPath('data.description', 'Intro course');

    $this->getJson('/api/projects', authHeader($user))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'CS 101 Spring');
});

test('faculty cannot access another users project', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    $this->getJson("/api/projects/{$project->id}", authHeader($other))
        ->assertNotFound();
});

test('faculty can update and delete own project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['title' => 'Old']);

    $this->patchJson("/api/projects/{$project->id}", [
        'title' => 'New title',
    ], authHeader($user))->assertOk()
        ->assertJsonPath('data.title', 'New title');

    $this->deleteJson("/api/projects/{$project->id}", [], authHeader($user))
        ->assertNoContent();

    expect(Project::query()->whereKey($project->id)->exists())->toBeFalse();
});

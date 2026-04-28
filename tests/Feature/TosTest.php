<?php

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use App\Models\SyllabusTopic;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

function facultyTosAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('faculty can create deterministic tos run and allocations sum to requested items', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $response = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 20,
        'topics' => [
            ['name' => 'Topic A', 'hours' => 3],
            ['name' => 'Topic B', 'hours' => 2],
        ],
        'bloom_distribution' => [
            'knowledge' => 20,
            'comprehension' => 30,
            'application' => 30,
            'analysis' => 20,
        ],
    ], facultyTosAuthHeader($user));

    $response->assertCreated()
        ->assertJsonPath('data.total_items', 20)
        ->assertJsonCount(8, 'data.allocations');

    $allocations = collect($response->json('data.allocations'));

    expect($allocations->sum('item_count'))->toBe(20);
    expect($allocations
        ->where('topic_name', 'Topic A')
        ->sum('item_count'))->toBe(12);
    expect($allocations
        ->where('topic_name', 'Topic B')
        ->sum('item_count'))->toBe(8);
});

test('tos run show and index are scoped to owner project', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    $created = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 10,
        'topics' => [
            ['name' => 'Topic A', 'hours' => 1],
            ['name' => 'Topic B', 'hours' => 1],
        ],
    ], facultyTosAuthHeader($owner))->assertCreated();

    $tosRunId = $created->json('data.id');

    $this->getJson("/api/projects/{$project->id}/tos-runs", facultyTosAuthHeader($owner))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/projects/{$project->id}/tos-runs/{$tosRunId}", facultyTosAuthHeader($owner))
        ->assertOk()
        ->assertJsonPath('data.id', $tosRunId);

    Auth::forgetGuards();

    $this->getJson("/api/projects/{$project->id}/tos-runs/{$tosRunId}", facultyTosAuthHeader($other))
        ->assertNotFound();
});

test('tos run request validates required fields', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 0,
        'topics' => [],
    ], facultyTosAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['total_items', 'topics']);
});

test('faculty can create tos run from extracted syllabus topics', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $document = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
    ]);

    SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $document->id,
        'topic_name' => 'Topic A',
        'hours' => 6,
        'objective' => 'Understand A',
    ]);

    SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $document->id,
        'topic_name' => 'Topic B',
        'hours' => 4,
        'objective' => 'Understand B',
    ]);

    $this->postJson("/api/projects/{$project->id}/tos-runs/from-syllabus", [
        'total_items' => 10,
        'syllabus_document_id' => $document->id,
    ], facultyTosAuthHeader($user))
        ->assertCreated()
        ->assertJsonPath('data.total_items', 10)
        ->assertJsonPath('data.topics.0.name', 'Topic A');
});

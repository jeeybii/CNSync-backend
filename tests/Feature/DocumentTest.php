<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function facultyAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('document upload stores file and runs stub job to ready', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $file = UploadedFile::fake()->create('syllabus.pdf', 200, 'application/pdf');

    $response = $this->post("/api/projects/{$project->id}/documents", [
        'kind' => 'syllabus',
        'file' => $file,
    ], facultyAuthHeader($user));

    $response->assertCreated();
    $id = $response->json('data.id');
    expect($id)->not->toBeNull();

    $document = Document::query()->findOrFail($id);
    expect($document->status)->toBe(DocumentStatus::Ready);
    Storage::disk('local')->assertExists($document->path);
});

test('document list and show are scoped to project', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $file = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $this->post("/api/projects/{$project->id}/documents", [
        'kind' => 'material',
        'file' => $file,
    ], facultyAuthHeader($user))->assertCreated();

    $this->getJson("/api/projects/{$project->id}/documents", facultyAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $docId = Document::query()->first()->id;

    $this->getJson("/api/projects/{$project->id}/documents/{$docId}", facultyAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.kind', 'material');
});

test('document delete removes file and row', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $file = UploadedFile::fake()->create('temp.pdf', 50, 'application/pdf');

    $created = $this->post("/api/projects/{$project->id}/documents", [
        'kind' => 'material',
        'file' => $file,
    ], facultyAuthHeader($user));

    $docId = $created->json('data.id');
    $path = Document::query()->findOrFail($docId)->path;

    Storage::disk('local')->assertExists($path);

    $this->deleteJson("/api/projects/{$project->id}/documents/{$docId}", [], facultyAuthHeader($user))
        ->assertNoContent();

    expect(Document::query()->whereKey($docId)->exists())->toBeFalse();
    Storage::disk('local')->assertMissing($path);
});

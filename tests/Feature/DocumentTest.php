<?php

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function facultyAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

function bindFakeDocumentExtractor(): void
{
    app()->bind(DocumentTextExtractor::class, fn () => new class extends DocumentTextExtractor
    {
        public function extract(Document $document): array
        {
            return [[
                'chunk_index' => 0,
                'content' => sprintf('Parsed content for %s', $document->original_name),
                'page_number' => 1,
                'metadata' => [
                    'source' => 'test_extractor',
                    'mime_type' => $document->mime_type,
                    'parser' => 'test',
                ],
            ]];
        }
    });
}

test('document upload stores file and writes lifecycle transition trail', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

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
    expect($document->status)->toBe(DocumentStatus::Analyzed);
    expect($document->statusTransitions()->count())->toBe(3);
    expect($document->extractedChunks()->count())->toBe(1);
    expect($document->extractedChunks()->first()?->metadata)->toMatchArray([
        'source' => 'test_extractor',
        'mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->assertExists($document->path);

    $this->getJson("/api/projects/{$project->id}/documents/{$id}/transitions", facultyAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.from_status', DocumentStatus::Uploaded->value)
        ->assertJsonPath('data.0.to_status', DocumentStatus::Parsing->value)
        ->assertJsonPath('data.1.from_status', DocumentStatus::Parsing->value)
        ->assertJsonPath('data.1.to_status', DocumentStatus::Extracted->value)
        ->assertJsonPath('data.2.from_status', DocumentStatus::Extracted->value)
        ->assertJsonPath('data.2.to_status', DocumentStatus::Analyzed->value);
});

test('document list and show are scoped to project', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

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
    bindFakeDocumentExtractor();

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

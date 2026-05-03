<?php

use App\Enums\DocumentKind;
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

test('project accepts only one syllabus upload', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->post("/api/projects/{$project->id}/documents", [
        'kind' => 'syllabus',
        'file' => UploadedFile::fake()->create('syllabus-1.pdf', 200, 'application/pdf'),
    ], facultyAuthHeader($user))->assertCreated();

    $this->postJson("/api/projects/{$project->id}/documents", [
        'kind' => 'syllabus',
        'file' => UploadedFile::fake()->create('syllabus-2.pdf', 200, 'application/pdf'),
    ], facultyAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only one syllabus document is allowed per project.');
});

test('project accepts at most ten learning materials', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Document::factory()->count(10)->for($project)->create(['kind' => DocumentKind::Material]);

    $this->postJson("/api/projects/{$project->id}/documents", [
        'kind' => 'material',
        'file' => UploadedFile::fake()->create('module-11.pdf', 200, 'application/pdf'),
    ], facultyAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A project can have at most 10 learning material documents.');
});

test('bundle upload accepts one syllabus and multiple materials in one request', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $response = $this->post("/api/projects/{$project->id}/documents/bundle", [
        'syllabus' => UploadedFile::fake()->create('syllabus.pdf', 200, 'application/pdf'),
        'materials' => [
            UploadedFile::fake()->create('material-1.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->create('material-2.pdf', 200, 'application/pdf'),
        ],
    ], facultyAuthHeader($user));

    $response->assertCreated()
        ->assertJsonPath('data.syllabus.kind', 'syllabus')
        ->assertJsonCount(2, 'data.materials');

    expect(Document::query()->where('project_id', $project->id)->count())->toBe(3);
});

test('bundle upload validates limits and uniqueness constraints', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Document::factory()->for($project)->create(['kind' => DocumentKind::Syllabus]);

    $this->post("/api/projects/{$project->id}/documents/bundle", [
        'syllabus' => UploadedFile::fake()->create('new-syllabus.pdf', 200, 'application/pdf'),
        'materials' => [
            UploadedFile::fake()->create('material-1.pdf', 200, 'application/pdf'),
        ],
    ], facultyAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only one syllabus document is allowed per project.');
});

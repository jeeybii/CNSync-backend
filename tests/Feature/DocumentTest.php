<?php

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use App\Models\SyllabusTopic;
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
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
    ]);
    $topic = SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $syllabus->id,
        'topic_name' => 'Networks',
        'hours' => 2,
        'week_number' => 1,
        'position' => 1,
    ]);

    $file = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

    $this->post("/api/projects/{$project->id}/documents", [
        'kind' => 'material',
        'file' => $file,
        'syllabus_topic_id' => $topic->id,
    ], facultyAuthHeader($user))->assertCreated();

    $this->getJson("/api/projects/{$project->id}/documents", facultyAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $docId = Document::query()->where('kind', DocumentKind::Material)->firstOrFail()->id;

    $this->getJson("/api/projects/{$project->id}/documents/{$docId}", facultyAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.kind', 'material')
        ->assertJsonPath('data.syllabus_topic_id', $topic->id);
});

test('document delete removes file and row', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
    ]);
    $topic = SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $syllabus->id,
        'topic_name' => 'Networks',
        'hours' => 2,
        'position' => 1,
    ]);

    $file = UploadedFile::fake()->create('temp.pdf', 50, 'application/pdf');

    $created = $this->post("/api/projects/{$project->id}/documents", [
        'kind' => 'material',
        'file' => $file,
        'syllabus_topic_id' => $topic->id,
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

test('project accepts at most one hundred learning materials for topic uploads', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Document::factory()->count(100)->for($project)->create(['kind' => DocumentKind::Material]);

    $this->postJson("/api/projects/{$project->id}/documents", [
        'kind' => 'material',
        'file' => UploadedFile::fake()->create('module-101.pdf', 200, 'application/pdf'),
    ], facultyAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A project can have at most 100 learning material documents.');
});

test('bundle upload cannot bypass syllabus-first topic assignment', function () {
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

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Upload and extract the syllabus first, then upload materials to its topics.');

    expect(Document::query()->where('project_id', $project->id)->count())->toBe(0);
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
        ->assertJsonPath('message', 'Upload and extract the syllabus first, then upload materials to its topics.');
});

test('materials require an extracted topic from the same project and multiple files can share it', function () {
    Storage::fake('local');
    bindFakeDocumentExtractor();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);
    $topic = SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $syllabus->id,
        'topic_name' => 'Network Hardware',
        'hours' => 2,
        'week_number' => 3,
        'position' => 1,
    ]);

    $upload = fn (int $topicId, string $name) => $this->post("/api/projects/{$project->id}/documents", [
        'kind' => 'material',
        'file' => UploadedFile::fake()->create($name, 100, 'application/pdf'),
        'syllabus_topic_id' => $topicId,
    ], facultyAuthHeader($user));

    $upload($topic->id, 'before-extraction.pdf')->assertUnprocessable();
    $syllabus->update(['status' => DocumentStatus::TosReady]);
    $upload($topic->id, 'first.pdf')->assertCreated()
        ->assertJsonPath('data.syllabus_topic_id', $topic->id)
        ->assertJsonPath('data.week_number', 3);
    $upload($topic->id, 'second.pdf')->assertCreated();

    $this->post("/api/projects/{$project->id}/syllabus-topics/{$topic->id}/materials", [
        'materials' => [
            UploadedFile::fake()->create('third.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('fourth.pdf', 100, 'application/pdf'),
        ],
    ], facultyAuthHeader($user))
        ->assertCreated()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.syllabus_topic_id', $topic->id);

    $this->getJson("/api/projects/{$project->id}/documents/{$syllabus->id}/syllabus-topics", facultyAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(4, 'data.0.materials');

    $otherProject = Project::factory()->for($user)->create();
    $otherSyllabus = Document::factory()->for($otherProject)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
    ]);
    $otherTopic = SyllabusTopic::query()->create([
        'project_id' => $otherProject->id,
        'document_id' => $otherSyllabus->id,
        'topic_name' => 'Other',
        'hours' => 1,
    ]);
    $upload($otherTopic->id, 'wrong-project.pdf')->assertUnprocessable();
    $this->post("/api/projects/{$project->id}/syllabus-topics/{$otherTopic->id}/materials", [
        'materials' => [UploadedFile::fake()->create('wrong-project.pdf', 100, 'application/pdf')],
    ], facultyAuthHeader($user))->assertUnprocessable();

    $legacyMaterial = Document::factory()->for($project)->create(['kind' => DocumentKind::Material]);
    $this->patchJson("/api/projects/{$project->id}/documents/{$legacyMaterial->id}/topic", [
        'syllabus_topic_id' => $otherTopic->id,
    ], facultyAuthHeader($user))->assertUnprocessable();
    $this->patchJson("/api/projects/{$project->id}/documents/{$legacyMaterial->id}/topic", [
        'syllabus_topic_id' => $topic->id,
    ], facultyAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.syllabus_topic_id', $topic->id)
        ->assertJsonPath('data.week_number', 3);
    expect($project->documents()->where('kind', DocumentKind::Material)->count())->toBe(5);
});

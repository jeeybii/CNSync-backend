<?php

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\ExtractedChunk;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function questionAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('faculty can generate grounded question run from tos allocations', function () {
    config()->set('services.gemini.api_key', 'test-key');

    Http::fakeSequence()
        ->push([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'question_text' => 'Malformed batch payload',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ],
                    ],
                ],
            ],
        ], 200)
        ->push([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'question_text' => 'What is a core CNSync workflow step?',
                                    'options' => ['A. Upload syllabus', 'B. Delete schema', 'C. Disable parsing', 'D. Skip validation'],
                                    'answer_key' => 'A',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ],
                    ],
                ],
            ],
        ], 200)
        ->push([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'question_text' => 'True or False: Learning materials are required for grounded generation.',
                                    'options' => ['True', 'False'],
                                    'answer_key' => 'True',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ],
                    ],
                ],
            ],
        ], 200);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $document = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);
    $material = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Material,
        'status' => DocumentStatus::Analyzed,
    ]);

    ExtractedChunk::query()->create([
        'document_id' => $document->id,
        'chunk_index' => 0,
        'content' => 'Topic A covers upload workflow and syllabus analysis.',
        'page_number' => 1,
        'metadata' => ['source' => 'test'],
    ]);
    ExtractedChunk::query()->create([
        'document_id' => $material->id,
        'chunk_index' => 0,
        'content' => 'Learning module on CNSync exam construction and item writing.',
        'page_number' => 2,
        'metadata' => ['source' => 'test'],
    ]);

    $tosRun = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 2,
        'topics' => [
            ['name' => 'Topic A', 'hours' => 1],
        ],
        'bloom_distribution' => [
            'knowledge' => 100,
            'understand' => 0,
            'apply' => 0,
            'analyze' => 0,
            'evaluate' => 0,
            'create' => 0,
        ],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    $response = $this->postJson("/api/projects/{$project->id}/question-runs", [
        'tos_run_id' => $tosRun['id'],
        'question_type_distribution' => [
            'multiple_choice' => 1,
            'true_false' => 1,
        ],
    ], questionAuthHeader($user));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.requested_items', 2)
        ->assertJsonPath('data.generated_items', 2)
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.0.question_type', 'multiple_choice')
        ->assertJsonPath('data.items.1.question_type', 'true_false');

    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'test-key')
        && ! str_contains($request->url(), '?key='));
});

test('question generation returns validation error when analyzed documents are missing', function () {
    config()->set('services.gemini.api_key', 'test-key');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Uploaded,
    ]);
    Document::factory()->for($project)->create([
        'kind' => DocumentKind::Material,
        'status' => DocumentStatus::Uploaded,
    ]);

    $tosRun = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 2,
        'topics' => [
            ['name' => 'Topic A', 'hours' => 1],
        ],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    $this->postJson("/api/projects/{$project->id}/question-runs", [
        'tos_run_id' => $tosRun['id'],
    ], questionAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Question generation requires one analyzed syllabus and at least one analyzed learning material.');
});

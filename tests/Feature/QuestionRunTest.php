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

    Http::fake([
        '*' => Http::response([
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
        ], 200),
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $document = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);

    ExtractedChunk::query()->create([
        'document_id' => $document->id,
        'chunk_index' => 0,
        'content' => 'Topic A covers upload workflow and syllabus analysis.',
        'page_number' => 1,
        'metadata' => ['source' => 'test'],
    ]);

    $tosRun = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 2,
        'topics' => [
            ['name' => 'Topic A', 'hours' => 1],
        ],
        'bloom_distribution' => [
            'knowledge' => 1,
            'comprehension' => 0,
            'application' => 0,
            'analysis' => 0,
        ],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    $response = $this->postJson("/api/projects/{$project->id}/question-runs", [
        'tos_run_id' => $tosRun['id'],
    ], questionAuthHeader($user));

    $response->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.requested_items', 2)
        ->assertJsonPath('data.generated_items', 2)
        ->assertJsonCount(2, 'data.items');

    Http::assertSentCount(3);
});

test('question generation returns validation error when analyzed documents are missing', function () {
    config()->set('services.gemini.api_key', 'test-key');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

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
        ->assertJsonPath('message', 'No analyzed documents are available for grounded question generation.');
});

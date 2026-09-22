<?php

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Assessment;
use App\Models\Document;
use App\Models\ExtractedChunk;
use App\Models\Project;
use App\Models\SyllabusTopic;
use App\Models\User;
use App\Services\GeminiQuestionGenerator;
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
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
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
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    $this->postJson("/api/projects/{$project->id}/question-runs", [
        'tos_run_id' => $tosRun['id'],
    ], questionAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Question generation requires one analyzed syllabus and at least one analyzed learning material.');
});

test('paired question generation produces both term exams with their own question mixes', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
        'suggested_bloom_levels_by_term' => [
            'midterm' => ['remembering', 'understanding', 'applying'],
            'final' => ['analyzing', 'evaluating', 'creating'],
        ],
    ]);
    Document::factory()->for($project)->create([
        'kind' => DocumentKind::Material,
        'status' => DocumentStatus::Analyzed,
    ]);

    foreach (['midterm', 'final'] as $index => $term) {
        SyllabusTopic::query()->create([
            'project_id' => $project->id,
            'document_id' => $syllabus->id,
            'topic_name' => ucfirst($term).' Topic',
            'hours' => 2,
            'term' => $term,
            'position' => $index + 1,
            'bloom_level' => $term === 'midterm' ? 'understanding' : 'analyzing',
        ]);
    }

    $tos = $this->postJson("/api/projects/{$project->id}/tos-runs/pair/from-syllabus", [
        'midterm' => [
            'total_items' => 1,
            'question_type_percentages' => ['multiple_choice' => 100],
        ],
        'final' => [
            'total_items' => 1,
            'question_type_percentages' => ['essay' => 100],
        ],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    app()->bind(GeminiQuestionGenerator::class, fn () => new class extends GeminiQuestionGenerator
    {
        public function generateBatch(array $requests, array $contextsByRequestId): array
        {
            $items = [];
            foreach ($requests as $request) {
                $items[$request['id']] = [
                    'question_text' => $request['topic_name'].' question',
                    'options' => $request['question_type'] === 'multiple_choice' ? ['A. Yes', 'B. No'] : [],
                    'answer_key' => 'Yes',
                ];
            }

            return $items;
        }
    });

    $questions = $this->postJson("/api/projects/{$project->id}/question-runs/pair", [
        'midterm_tos_run_id' => $tos['midterm']['tos_run']['id'],
        'final_tos_run_id' => $tos['final']['tos_run']['id'],
    ], questionAuthHeader($user))
        ->assertCreated()
        ->assertJsonPath('data.midterm.items.0.question_type', 'multiple_choice')
        ->assertJsonPath('data.final.items.0.question_type', 'essay')
        ->json('data');

    $this->postJson('/api/assessments/pair', [
        'project_id' => $project->id,
        'midterm' => ['question_run_id' => $questions['midterm']['id'], 'title' => 'Midterm Exam'],
        'final' => ['question_run_id' => $questions['final']['id'], 'title' => 'Final Exam'],
    ], questionAuthHeader($user))
        ->assertCreated()
        ->assertJsonPath('data.midterm.exam_type', 'midterm')
        ->assertJsonPath('data.final.exam_type', 'final');

    expect($project->questionRuns()->count())->toBe(2);
    expect(Assessment::query()->where('project_id', $project->id)->count())->toBe(2);

    $this->postJson("/api/projects/{$project->id}/question-runs/pair", [
        'midterm_tos_run_id' => $tos['midterm']['tos_run']['id'],
        'final_tos_run_id' => $tos['final']['tos_run']['id'],
    ], questionAuthHeader($user))->assertCreated();
    expect($project->questionRuns()->count())->toBe(2);
});

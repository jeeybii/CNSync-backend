<?php

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\ExtractedChunk;
use App\Models\Project;
use App\Models\SyllabusTopic;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function syllabusAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('faculty can extract structured syllabus topics from analyzed syllabus document', function () {
    config()->set('services.gemini.api_key', 'test-key');

    Http::fake([
        '*' => Http::response([
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode([
                                    'topics' => [
                                        [
                                            'topic_name' => 'Neural Networks',
                                            'hours' => 6,
                                            'objective' => 'Explain and apply basic ANN models',
                                            'bloom_level' => 'apply',
                                            'confidence' => 0.92,
                                        ],
                                        [
                                            'topic_name' => 'Convolutional Networks',
                                            'hours' => 4,
                                            'objective' => 'Analyze CNN components',
                                            'bloom_level' => 'analyze',
                                            'confidence' => 0.88,
                                        ],
                                    ],
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ],
                    ],
                ],
            ],
        ]),
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
        'content' => 'Week 1-3 Neural Networks 6 hours',
        'page_number' => 1,
    ]);

    $this->postJson("/api/projects/{$project->id}/documents/{$document->id}/extract-syllabus", [], syllabusAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.topic_name', 'Neural Networks')
        ->assertJsonPath('data.0.position', 1)
        ->assertJsonPath('data.1.position', 2);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::TosReady);
    expect($document->syllabusTopics()->count())->toBe(2);

    $this->getJson("/api/projects/{$project->id}/documents/{$document->id}/syllabus-topics", syllabusAuthHeader($user))
        ->assertJsonPath('data.0.topic_name', 'Neural Networks')
        ->assertJsonPath('data.1.topic_name', 'Convolutional Networks');

    Document::factory()->for($project)->create([
        'kind' => DocumentKind::Material,
        'syllabus_topic_id' => $document->syllabusTopics()->firstOrFail()->id,
    ]);
    $this->postJson("/api/projects/{$project->id}/documents/{$document->id}/extract-syllabus", [], syllabusAuthHeader($user))
        ->assertStatus(409);
    $this->deleteJson("/api/projects/{$project->id}/documents/{$document->id}", [], syllabusAuthHeader($user))
        ->assertStatus(409);

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'test-key')
        && ! str_contains($request->url(), '?key='));
});

test('syllabus extraction rejects non syllabus document', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $document = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Material,
        'status' => DocumentStatus::Analyzed,
    ]);

    $this->postJson("/api/projects/{$project->id}/documents/{$document->id}/extract-syllabus", [], syllabusAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Only syllabus documents can be processed by syllabus extraction.');
});

test('faculty can review and edit extracted syllabus topics', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $document = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
    ]);

    $topicA = SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $document->id,
        'topic_name' => 'Old Topic A',
        'hours' => 3,
        'objective' => 'Old objective A',
        'bloom_level' => 'remembering',
    ]);

    $topicB = SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $document->id,
        'topic_name' => 'Old Topic B',
        'hours' => 2,
        'objective' => 'Old objective B',
        'bloom_level' => 'understanding',
    ]);

    $this->getJson("/api/projects/{$project->id}/documents/{$document->id}/syllabus-topics", syllabusAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->putJson("/api/projects/{$project->id}/documents/{$document->id}/syllabus-topics", [
        'topics' => [
            [
                'id' => $topicA->id,
                'topic_name' => 'Neural Networks',
                'hours' => 6,
                'objective' => 'Explain ANN architecture',
                'bloom_level' => 'applying',
            ],
            [
                'id' => $topicB->id,
                'topic_name' => 'CNN Fundamentals',
                'hours' => 4,
                'objective' => 'Analyze convolution layers',
                'bloom_level' => 'analyzing',
            ],
        ],
    ], syllabusAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.0.topic_name', 'Neural Networks')
        ->assertJsonPath('data.1.topic_name', 'CNN Fundamentals');

    expect($topicA->fresh()->hours)->toBe(6.0);
    expect($topicB->fresh()->bloom_level->value)->toBe('analyzing');

    $this->putJson("/api/projects/{$project->id}/documents/{$document->id}/syllabus-topics", [
        'topics' => [
            ['id' => $topicA->id, 'topic_name' => 'Neural Networks', 'hours' => 6, 'week_number' => 1],
            ['id' => $topicB->id, 'topic_name' => 'CNN Fundamentals', 'hours' => 4, 'week_number' => 1],
        ],
    ], syllabusAuthHeader($user))->assertUnprocessable()->assertJsonValidationErrors('topics');
});

test('extraction assigns one week per main topic and suggests three whole-syllabus bloom levels', function () {
    config()->set('services.gemini.api_key', 'test-key');
    Http::fake(['*' => Http::response(['candidates' => [[
        'content' => ['parts' => [['text' => json_encode([
            'suggested_bloom_levels_by_term' => [
                'midterm' => ['remembering', 'applying', 'evaluating'],
                'final' => ['analyzing', 'evaluating', 'creating'],
            ],
            'suggested_question_type_percentages_by_term' => [
                'midterm' => ['multiple_choice' => 50, 'true_false' => 20, 'identification' => 0, 'essay' => 30],
                'final' => ['multiple_choice' => 20, 'true_false' => 10, 'identification' => 20, 'essay' => 50],
            ],
            'topics' => [
                ['topic_name' => 'Network Fundamentals', 'hours' => 2, 'term' => 'midterm', 'bloom_level' => 'remembering'],
                ['topic_name' => 'Network Models', 'hours' => 2, 'term' => 'midterm', 'bloom_level' => 'applying'],
                ['topic_name' => 'Security', 'hours' => 2, 'term' => 'final', 'bloom_level' => 'analyzing'],
            ],
        ], JSON_THROW_ON_ERROR)]]],
    ]]], 200)]);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $document = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);
    ExtractedChunk::query()->create([
        'document_id' => $document->id,
        'chunk_index' => 0,
        'content' => 'MIDTERM 1. Network Fundamentals 2. Network Models FINAL 3. Security',
        'page_number' => 1,
    ]);

    $this->postJson("/api/projects/{$project->id}/documents/{$document->id}/extract-syllabus", [], syllabusAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('suggested_bloom_levels_by_term.midterm', ['remembering', 'applying', 'evaluating'])
        ->assertJsonPath('suggested_bloom_levels_by_term.final', ['analyzing', 'evaluating', 'creating'])
        ->assertJsonPath('suggested_question_type_percentages_by_term.midterm.multiple_choice', 50)
        ->assertJsonPath('suggested_question_type_percentages_by_term.final.essay', 50)
        ->assertJsonPath('data.0.week_number', 1)
        ->assertJsonPath('data.1.week_number', 2)
        ->assertJsonPath('data.2.week_number', 3)
        ->assertJsonPath('data.2.term', 'final');
});

test('syllabus extraction requests structured json and accepts a fenced json payload', function () {
    config()->set('services.gemini.api_key', 'test-key');
    Http::fake(['*' => Http::response(['candidates' => [[
        'content' => ['parts' => [['text' => "```json\n".json_encode([
            'topics' => [[
                'topic_name' => 'Network Fundamentals',
                'hours' => 2,
                'objective' => null,
                'bloom_level' => 'understanding',
                'confidence' => 0.9,
                'term' => 'midterm',
                'week_number' => 1,
            ]],
            'suggested_bloom_levels_by_term' => [
                'midterm' => ['remembering', 'understanding', 'applying'],
                'final' => ['analyzing', 'evaluating', 'creating'],
            ],
            'suggested_question_type_percentages_by_term' => [
                'midterm' => ['multiple_choice' => 40, 'true_false' => 20, 'identification' => 20, 'essay' => 0, 'matching_type' => 20],
                'final' => ['multiple_choice' => 20, 'true_false' => 0, 'identification' => 0, 'essay' => 80, 'matching_type' => 0],
            ],
        ], JSON_THROW_ON_ERROR)."\n```"]]],
    ]]], 200)]);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $document = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);
    ExtractedChunk::query()->create([
        'document_id' => $document->id,
        'chunk_index' => 0,
        'content' => 'MIDTERM Network Fundamentals',
        'page_number' => 1,
    ]);

    $this->postJson("/api/projects/{$project->id}/documents/{$document->id}/extract-syllabus", [], syllabusAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.0.topic_name', 'Network Fundamentals');

    Http::assertSent(function ($request): bool {
        $config = $request->data()['generationConfig'] ?? [];

        return ($config['responseMimeType'] ?? null) === 'application/json'
            && ($config['maxOutputTokens'] ?? null) === 65536
            && data_get($config, 'responseSchema.properties.topics.type') === 'ARRAY';
    });
});

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
        ->assertJsonPath('data.0.topic_name', 'Neural Networks');

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::TosReady);
    expect($document->syllabusTopics()->count())->toBe(2);

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
        'bloom_level' => 'knowledge',
    ]);

    $topicB = SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $document->id,
        'topic_name' => 'Old Topic B',
        'hours' => 2,
        'objective' => 'Old objective B',
        'bloom_level' => 'understand',
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
                'bloom_level' => 'apply',
            ],
            [
                'id' => $topicB->id,
                'topic_name' => 'CNN Fundamentals',
                'hours' => 4,
                'objective' => 'Analyze convolution layers',
                'bloom_level' => 'analyze',
            ],
        ],
    ], syllabusAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.0.topic_name', 'Neural Networks')
        ->assertJsonPath('data.1.topic_name', 'CNN Fundamentals');

    expect($topicA->fresh()->hours)->toBe(6.0);
    expect($topicB->fresh()->bloom_level->value)->toBe('analyze');
});

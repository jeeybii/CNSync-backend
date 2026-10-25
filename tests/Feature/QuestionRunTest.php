<?php

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Api\QuestionRunController;
use App\Jobs\GenerateQuestionRunJob;
use App\Models\Assessment;
use App\Models\Document;
use App\Models\ExtractedChunk;
use App\Models\Project;
use App\Models\SyllabusTopic;
use App\Models\User;
use App\Services\GeminiQuestionGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function questionAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('faculty can queue question generation without holding the http request open', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);
    $tosRun = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 1,
        'topics' => [['name' => 'Routing', 'hours' => 1]],
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    $response = $this->postJson("/api/projects/{$project->id}/question-runs/async", [
        'tos_run_id' => $tosRun['id'],
        'question_type_distribution' => ['multiple_choice' => 1],
    ], questionAuthHeader($user));

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.requested_items', 1);

    Queue::assertPushed(GenerateQuestionRunJob::class, fn (GenerateQuestionRunJob $job): bool => $job->questionRunId === $response->json('data.id')
        && $job->projectId === $project->id
        && $job->userId === $user->id
    );
});

test('faculty cancellation marks active question runs so queued jobs stop', function () {
    Queue::fake();

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $otherProject = Project::factory()->for($user)->create();
    $tosRun = $project->tosRuns()->create([
        'total_items' => 10,
        'topics' => [['name' => 'Routing', 'hours' => 1]],
        'bloom_distribution' => ['remembering' => 1],
    ]);
    $otherTosRun = $otherProject->tosRuns()->create([
        'total_items' => 10,
        'topics' => [['name' => 'Security', 'hours' => 1]],
        'bloom_distribution' => ['remembering' => 1],
    ]);
    $active = $project->questionRuns()->create([
        'tos_run_id' => $tosRun->id,
        'requested_items' => 10,
        'status' => 'processing',
    ]);
    $completed = $project->questionRuns()->create([
        'tos_run_id' => $tosRun->id,
        'requested_items' => 10,
        'status' => 'completed',
    ]);
    $other = $otherProject->questionRuns()->create([
        'tos_run_id' => $otherTosRun->id,
        'requested_items' => 10,
        'status' => 'processing',
    ]);

    $this->postJson("/api/projects/{$project->id}/question-runs/cancel", [], questionAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.cancelled_count', 1)
        ->assertJsonPath('data.question_run_ids.0', $active->id);

    expect($active->fresh()->status)->toBe('cancelled')
        ->and($active->fresh()->failed_reason)->toBe('Cancelled by user.')
        ->and($completed->fresh()->status)->toBe('completed')
        ->and($other->fresh()->status)->toBe('processing');
});

test('failed generation resumes after the last persisted batch', function () {
    config()->set('services.ai.question_batch_size', 1);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);
    ExtractedChunk::query()->create([
        'document_id' => $syllabus->id,
        'chunk_index' => 0,
        'content' => 'Routers forward packets by consulting routing tables.',
        'page_number' => 1,
        'metadata' => [],
    ]);
    $tosRun = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 2,
        'topics' => [['name' => 'Routing', 'hours' => 1]],
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    app()->bind(GeminiQuestionGenerator::class, fn () => new class extends GeminiQuestionGenerator
    {
        private int $calls = 0;

        public function generateBatch(array $requests, array $contextsByRequestId): array
        {
            $this->calls++;
            if ($this->calls === 2) {
                throw new ConnectionException('AI connection interrupted.');
            }

            $request = $requests[0];

            return [$request['id'] => [
                'question_text' => 'What does a router consult when forwarding packets?',
                'options' => ['A routing table', 'A keyboard map', 'A print queue', 'A display profile'],
                'answer_key' => 'A',
            ]];
        }
    });

    $this->postJson("/api/projects/{$project->id}/question-runs", [
        'tos_run_id' => $tosRun['id'],
        'question_type_distribution' => ['multiple_choice' => 2],
    ], questionAuthHeader($user))->assertServiceUnavailable();

    $questionRun = $project->questionRuns()->latest('id')->firstOrFail();
    expect($questionRun->generated_items)->toBe(1)
        ->and($questionRun->items()->count())->toBe(1)
        ->and($questionRun->status)->toBe('failed');

    $resumingGenerator = new class extends GeminiQuestionGenerator
    {
        public int $requestedItems = 0;

        public function generateBatch(array $requests, array $contextsByRequestId): array
        {
            $this->requestedItems += count($requests);
            $request = $requests[0];

            return [$request['id'] => [
                'question_text' => 'Which routing-table value identifies the next destination?',
                'options' => ['Next hop', 'Font size', 'Page margin', 'Color depth'],
                'answer_key' => 'A',
            ]];
        }
    };

    (new GenerateQuestionRunJob(
        $questionRun->id,
        $project->id,
        $tosRun['id'],
        $user->id,
        ['multiple_choice' => 2],
        '127.0.0.1',
    ))->handle(app(QuestionRunController::class), $resumingGenerator);

    $questionRun->refresh();
    expect($resumingGenerator->requestedItems)->toBe(1)
        ->and($questionRun->generated_items)->toBe(2)
        ->and($questionRun->items()->count())->toBe(2)
        ->and($questionRun->status)->toBe('completed');
});

test('faculty can generate grounded question run from tos allocations', function () {
    config()->set('services.gemini.api_key', 'test-key');
    Log::spy();

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
    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message, array $context): bool => $message === 'Exam generation finished.'
            && $context['project_id'] === $project->id
            && $context['question_run_id'] === $response->json('data.id')
            && $context['status'] === 'completed'
            && $context['item_count'] === 2
            && is_int($context['duration_ms'])
            && is_float($context['duration_seconds'])
    )->once();
});

test('faculty can generate questions from syllabus context without learning materials', function () {
    config()->set('services.gemini.api_key', 'test-key');

    Http::fake(function ($request) {
        $prompt = (string) data_get($request->data(), 'contents.0.parts.0.text', '');
        preg_match('/id=(alloc-\d+-item-1)/', $prompt, $matches);

        return Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'items' => [[
                            'request_id' => $matches[1] ?? 'missing-request-id',
                            'question_text' => 'Which device forwards packets between networks?',
                            'question_type' => 'multiple_choice',
                            'options' => ['A. Router', 'B. Keyboard', 'C. Monitor', 'D. Printer'],
                            'answer_key' => 'A',
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ]]],
            ]],
        ], 200);
    });

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);
    ExtractedChunk::query()->create([
        'document_id' => $syllabus->id,
        'chunk_index' => 0,
        'content' => 'Network routing covers routers, destination IP addresses, and routing tables.',
        'page_number' => 1,
        'metadata' => ['source' => 'syllabus'],
    ]);
    SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $syllabus->id,
        'topic_name' => 'Network Routing',
        'objective' => 'Explain how routers forward packets between networks.',
        'hours' => 2,
        'position' => 1,
    ]);

    $tosRun = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 1,
        'topics' => [['name' => 'Network Routing', 'hours' => 2]],
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    $this->postJson("/api/projects/{$project->id}/question-runs", [
        'tos_run_id' => $tosRun['id'],
        'question_type_distribution' => ['multiple_choice' => 1],
    ], questionAuthHeader($user))
        ->assertCreated()
        ->assertJsonPath('data.generated_items', 1)
        ->assertJsonPath('data.items.0.question_text', 'Which device forwards packets between networks?');

    Http::assertSent(fn ($request): bool => str_contains(
        (string) data_get($request->data(), 'contents.0.parts.0.text', ''),
        'Network routing covers routers'
    ));
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
        ->assertJsonPath('message', 'Question generation requires an analyzed syllabus.');
});

test('duplicate matching generation falls back to multiple choice instead of failing the run', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::Analyzed,
    ]);
    ExtractedChunk::query()->create([
        'document_id' => $syllabus->id,
        'chunk_index' => 0,
        'content' => 'Network administrators manage accounts, backups, monitoring, and storage.',
        'page_number' => 1,
        'metadata' => ['source' => 'syllabus'],
    ]);

    $tosRun = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 1,
        'topics' => [['name' => 'Network Management', 'hours' => 2]],
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
    ], questionAuthHeader($user))->assertCreated()->json('data');

    app()->bind(GeminiQuestionGenerator::class, fn () => new class extends GeminiQuestionGenerator
    {
        public function generateBatch(array $requests, array $contextsByRequestId): array
        {
            return [];
        }

        public function generateOne(
            string $topicName,
            string $bloomLevel,
            string $questionType,
            array $contexts,
            array $avoidQuestions = [],
            ?string $providerOverride = null,
        ): array {
            if ($questionType === 'matching_type') {
                throw new RuntimeException('AI could not produce a valid question after quality checks: The question duplicates an existing exam item.');
            }

            return [
                'question_text' => 'Which practice helps protect network accounts?',
                'options' => ['Strong authentication', 'Shared passwords', 'Open permissions', 'Disabled auditing'],
                'answer_key' => 'A',
                'citations' => [],
            ];
        }
    });

    $this->postJson("/api/projects/{$project->id}/question-runs", [
        'tos_run_id' => $tosRun['id'],
        'question_type_distribution' => ['matching_type' => 1],
    ], questionAuthHeader($user))
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.generated_items', 1)
        ->assertJsonPath('data.items.0.question_type', 'multiple_choice')
        ->assertJsonPath('data.items.0.question_text', 'Which practice helps protect network accounts?');
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

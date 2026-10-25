<?php

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Project;
use App\Models\SyllabusTopic;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

function facultyTosAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('faculty can create deterministic tos run and allocations sum to requested items', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Document::factory()->for($project)->create(['kind' => DocumentKind::Syllabus, 'status' => DocumentStatus::TosReady]);
    Document::factory()->for($project)->create(['kind' => DocumentKind::Material, 'status' => DocumentStatus::Analyzed]);

    $response = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 20,
        'topics' => [
            ['name' => 'Topic A', 'hours' => 3],
            ['name' => 'Topic B', 'hours' => 2],
        ],
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
    ], facultyTosAuthHeader($user));

    $response->assertCreated()
        ->assertJsonPath('data.total_items', 20)
        ->assertJsonCount(6, 'data.allocations');

    $allocations = collect($response->json('data.allocations'));

    expect($allocations->sum('item_count'))->toBe(20);
    expect($allocations
        ->where('topic_name', 'Topic A')
        ->sum('item_count'))->toBe(12);
    expect($allocations
        ->where('topic_name', 'Topic B')
        ->sum('item_count'))->toBe(8);
});

test('tos run show and index are scoped to owner project', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    Document::factory()->for($project)->create(['kind' => DocumentKind::Syllabus, 'status' => DocumentStatus::TosReady]);
    Document::factory()->for($project)->create(['kind' => DocumentKind::Material, 'status' => DocumentStatus::Analyzed]);

    $created = $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 10,
        'topics' => [
            ['name' => 'Topic A', 'hours' => 1],
            ['name' => 'Topic B', 'hours' => 1],
        ],
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
    ], facultyTosAuthHeader($owner))->assertCreated();

    $tosRunId = $created->json('data.id');

    $this->getJson("/api/projects/{$project->id}/tos-runs", facultyTosAuthHeader($owner))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/projects/{$project->id}/tos-runs/{$tosRunId}", facultyTosAuthHeader($owner))
        ->assertOk()
        ->assertJsonPath('data.id', $tosRunId);

    Auth::forgetGuards();

    $this->getJson("/api/projects/{$project->id}/tos-runs/{$tosRunId}", facultyTosAuthHeader($other))
        ->assertNotFound();
});

test('tos run request validates required fields', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Document::factory()->for($project)->create(['kind' => DocumentKind::Syllabus, 'status' => DocumentStatus::TosReady]);
    Document::factory()->for($project)->create(['kind' => DocumentKind::Material, 'status' => DocumentStatus::Analyzed]);

    $this->postJson("/api/projects/{$project->id}/tos-runs", [
        'total_items' => 0,
        'topics' => [],
    ], facultyTosAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['total_items', 'topics']);
});

test('faculty can create tos run from extracted syllabus topics', function () {
    config()->set('services.gemini.api_key', 'test-key');
    Http::fake([
        '*' => Http::response([
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'text' => json_encode([
                            'knowledge' => 0.1,
                            'understand' => 0.2,
                            'apply' => 0.4,
                            'analyze' => 0.1,
                            'evaluate' => 0.1,
                            'create' => 0.1,
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ],
            ]],
        ], 200),
    ]);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $document = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
    ]);
    Document::factory()->for($project)->create([
        'kind' => DocumentKind::Material,
        'status' => DocumentStatus::Analyzed,
    ]);

    SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $document->id,
        'topic_name' => 'Topic A',
        'hours' => 6,
        'objective' => 'Understand A',
        'position' => 2,
    ]);

    $firstInSyllabus = SyllabusTopic::query()->create([
        'project_id' => $project->id,
        'document_id' => $document->id,
        'topic_name' => 'Topic B',
        'hours' => 4,
        'objective' => 'Understand B',
        'position' => 1,
    ]);

    $this->postJson("/api/projects/{$project->id}/tos-runs/from-syllabus", [
        'total_items' => 10,
        'syllabus_document_id' => $document->id,
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
    ], facultyTosAuthHeader($user))
        ->assertCreated()
        ->assertJsonPath('data.total_items', 10)
        ->assertJsonPath('data.topics.0.name', 'Topic B')
        ->assertJsonPath('data.allocations.0.topic_name', 'Topic B')
        ->assertJsonPath('data.allocations.0.syllabus_topic_id', $firstInSyllabus->id)
        ->assertJsonPath('data.bloom_distribution.applying', 1 / 3);
});

test('term scoped tos can be generated without learning materials', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
    ]);
    foreach ([['Topic A', 'midterm', 1], ['Topic B', 'midterm', 2], ['Topic C', 'final', 3]] as [$name, $term, $week]) {
        SyllabusTopic::query()->create([
            'project_id' => $project->id,
            'document_id' => $syllabus->id,
            'topic_name' => $name,
            'hours' => 2,
            'term' => $term,
            'week_number' => $week,
        ]);
    }
    $payload = [
        'total_items' => 6,
        'syllabus_document_id' => $syllabus->id,
        'bloom_levels' => ['remembering', 'understanding', 'applying'],
        'term' => 'midterm',
    ];
    $url = "/api/projects/{$project->id}/tos-runs/from-syllabus";
    $this->postJson($url, $payload, facultyTosAuthHeader($user))
        ->assertCreated()
        ->assertJsonCount(2, 'data.topics')
        ->assertJsonPath('data.term', 'midterm');
});

test('paired tos creates both terms with independent item counts and overridable question mixes', function () {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $syllabus = Document::factory()->for($project)->create([
        'kind' => DocumentKind::Syllabus,
        'status' => DocumentStatus::TosReady,
        'suggested_bloom_levels_by_term' => [
            'midterm' => ['remembering', 'understanding', 'applying'],
            'final' => ['analyzing', 'evaluating', 'creating'],
        ],
        'suggested_question_type_percentages_by_term' => [
            'midterm' => ['multiple_choice' => 50, 'true_false' => 20, 'identification' => 0, 'essay' => 30],
            'final' => ['multiple_choice' => 20, 'true_false' => 10, 'identification' => 20, 'essay' => 50],
        ],
    ]);
    Document::factory()->for($project)->create(['kind' => DocumentKind::Material]);

    foreach ([['Midterm Topic', 'midterm', 1], ['Final Topic', 'final', 2]] as [$name, $term, $position]) {
        SyllabusTopic::query()->create([
            'project_id' => $project->id,
            'document_id' => $syllabus->id,
            'topic_name' => $name,
            'hours' => 2,
            'term' => $term,
            'position' => $position,
            'bloom_level' => $term === 'midterm' ? 'understanding' : 'analyzing',
        ]);
    }

    $this->postJson("/api/projects/{$project->id}/tos-runs/pair/from-syllabus", [
        'syllabus_document_id' => $syllabus->id,
        'midterm' => ['total_items' => 10],
        'final' => [
            'total_items' => 7,
            'bloom_levels' => ['analyzing', 'evaluating', 'creating'],
            'bloom_percentages' => [
                'analyzing' => 50,
                'evaluating' => 30,
                'creating' => 20,
            ],
            'question_type_percentages' => [
                'multiple_choice' => 0,
                'true_false' => 0,
                'identification' => 0,
                'essay' => 100,
            ],
        ],
    ], facultyTosAuthHeader($user))
        ->assertCreated()
        ->assertJsonPath('data.midterm.tos_run.term', 'midterm')
        ->assertJsonPath('data.final.tos_run.term', 'final')
        ->assertJsonPath('data.midterm.question_type_distribution.multiple_choice', 5)
        ->assertJsonPath('data.midterm.question_type_distribution.true_false', 2)
        ->assertJsonPath('data.midterm.question_type_distribution.essay', 3)
        ->assertJsonPath('data.final.question_type_distribution.essay', 7)
        ->assertJsonPath('data.final.bloom_percentages.analyzing', 50)
        ->assertJsonPath('data.final.tos_run.bloom_distribution.evaluating', 0.3);

    expect($project->tosRuns()->count())->toBe(2);
});

test('paired tos rejects bloom percentages that do not total one hundred', function () {
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
    Document::factory()->for($project)->create(['kind' => DocumentKind::Material]);
    foreach (['midterm', 'final'] as $position => $term) {
        SyllabusTopic::query()->create([
            'project_id' => $project->id,
            'document_id' => $syllabus->id,
            'topic_name' => ucfirst($term).' Topic',
            'hours' => 2,
            'term' => $term,
            'position' => $position + 1,
            'bloom_level' => $term === 'midterm' ? 'understanding' : 'analyzing',
        ]);
    }

    $this->postJson("/api/projects/{$project->id}/tos-runs/pair/from-syllabus", [
        'syllabus_document_id' => $syllabus->id,
        'midterm' => [
            'total_items' => 10,
            'bloom_levels' => ['remembering', 'understanding', 'applying'],
            'bloom_percentages' => ['remembering' => 40, 'understanding' => 30, 'applying' => 20],
        ],
        'final' => ['total_items' => 10],
    ], facultyTosAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('midterm.bloom_percentages');
});

test('paired tos requires exactly three bloom levels per term', function () {
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
    Document::factory()->for($project)->create(['kind' => DocumentKind::Material]);
    foreach (['midterm', 'final'] as $position => $term) {
        SyllabusTopic::query()->create([
            'project_id' => $project->id,
            'document_id' => $syllabus->id,
            'topic_name' => ucfirst($term).' Topic',
            'hours' => 2,
            'term' => $term,
            'position' => $position + 1,
            'bloom_level' => $term === 'midterm' ? 'understanding' : 'analyzing',
        ]);
    }

    $this->postJson("/api/projects/{$project->id}/tos-runs/pair/from-syllabus", [
        'syllabus_document_id' => $syllabus->id,
        'midterm' => [
            'total_items' => 10,
            'bloom_levels' => ['remembering', 'understanding', 'applying', 'analyzing'],
            'bloom_percentages' => ['remembering' => 25, 'understanding' => 25, 'applying' => 25, 'analyzing' => 25],
        ],
        'final' => ['total_items' => 10],
    ], facultyTosAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('midterm.bloom_levels');
});

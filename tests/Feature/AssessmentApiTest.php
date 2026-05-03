<?php

use App\Models\Deck;
use App\Models\Project;
use App\Models\User;

function assessmentAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

function seedQuestionRunForAssessment(User $user): array
{
    $project = Project::factory()->for($user)->create();
    $tosRun = $project->tosRuns()->create([
        'total_items' => 1,
        'topics' => [['name' => 'Topic A', 'hours' => 1]],
        'bloom_distribution' => [
            'knowledge' => 100,
            'understand' => 0,
            'apply' => 0,
            'analyze' => 0,
            'evaluate' => 0,
            'create' => 0,
        ],
    ]);
    $questionRun = $project->questionRuns()->create([
        'tos_run_id' => $tosRun->id,
        'requested_items' => 0,
        'generated_items' => 0,
        'status' => 'completed',
    ]);

    return [$project, $questionRun];
}

test('faculty can save and retrieve assessment metadata', function () {
    $user = User::factory()->create();
    [$project, $questionRun] = seedQuestionRunForAssessment($user);

    $tosPayload = [
        [
            'topic' => 'Topic A',
            'hours' => 1,
            'bloomLevel' => 1,
            'weight' => 100,
            'items' => 1,
        ],
    ];

    $created = $this->postJson('/api/assessments', [
        'project_id' => $project->id,
        'question_run_id' => $questionRun->id,
        'title' => 'Midterm Exam',
        'exam_type' => 'midterm',
        'number_of_items' => 3,
        'tos' => $tosPayload,
    ], assessmentAuthHeader($user))->assertCreated()->json('data');

    expect($created['title'])->toBe('Midterm Exam')
        ->and($created['question_items'])->toBeArray();

    $this->getJson('/api/assessments', assessmentAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/assessments/{$created['id']}", assessmentAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.title', 'Midterm Exam');

    $this->deleteJson("/api/assessments/{$created['id']}", [], assessmentAuthHeader($user))
        ->assertNoContent();

    expect(\App\Models\Assessment::query()->count())->toBe(0);
});

test('faculty can save deck linked to assessment', function () {
    $user = User::factory()->create();
    [$project, $questionRun] = seedQuestionRunForAssessment($user);

    $assessment = \App\Models\Assessment::query()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'question_run_id' => $questionRun->id,
        'title' => 'Exam',
        'exam_type' => 'final',
        'number_of_items' => 1,
        'tos' => [],
    ]);

    $this->postJson('/api/decks', [
        'title' => 'Study deck',
        'description' => 'Desc',
        'tags' => ['a', 'b'],
        'visibility' => 'private',
        'course_code' => 'CS101',
        'course_title' => 'Intro',
        'faculty_name' => 'Prof',
        'exam_type' => 'final',
        'number_of_items' => 1,
        'tos' => [['topic' => 'T', 'hours' => 1, 'bloomLevel' => 1, 'weight' => 100, 'items' => 1]],
        'questions' => [['id' => 1, 'type' => 'multiple_choice', 'bloomLevel' => 1, 'topic' => 'T', 'question' => 'Q?']],
        'source_assessment_id' => $assessment->id,
    ], assessmentAuthHeader($user))->assertCreated();

    $this->getJson('/api/decks', assessmentAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(Deck::query()->count())->toBe(1);
});

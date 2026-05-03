<?php

use App\Models\Project;
use App\Models\User;

function questionItemAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

function seedQuestionRunFor(User $user): array
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

test('faculty can create a multiple choice question item', function () {
    $user = User::factory()->create();
    [$project, $questionRun] = seedQuestionRunFor($user);

    $response = $this->postJson("/api/projects/{$project->id}/question-runs/{$questionRun->id}/items", [
        'topic_name' => 'Topic A',
        'bloom_level' => 'knowledge',
        'question_type' => 'multiple_choice',
        'question_text' => 'What is a vector?',
        'options' => ['A. Apple', 'B. Banana', 'C. Cherry', 'D. Date'],
        'answer_key' => 'C',
    ], questionItemAuthHeader($user));

    $response->assertCreated()
        ->assertJsonPath('data.sequence', 1)
        ->assertJsonPath('data.answer_key', 'C');

    $questionRun->refresh();
    expect($questionRun->generated_items)->toBe(1);
});

test('faculty can create a true false question item', function () {
    $user = User::factory()->create();
    [$project, $questionRun] = seedQuestionRunFor($user);

    $this->postJson("/api/projects/{$project->id}/question-runs/{$questionRun->id}/items", [
        'topic_name' => 'Topic A',
        'bloom_level' => 'understand',
        'question_type' => 'true_false',
        'question_text' => 'The sky is blue.',
        'options' => ['True', 'False'],
        'answer_key' => 'True',
    ], questionItemAuthHeader($user))
        ->assertCreated()
        ->assertJsonPath('data.answer_key', 'True');
});

test('faculty can update a question item', function () {
    $user = User::factory()->create();
    [$project, $questionRun] = seedQuestionRunFor($user);

    $created = $this->postJson("/api/projects/{$project->id}/question-runs/{$questionRun->id}/items", [
        'topic_name' => 'Topic A',
        'bloom_level' => 'knowledge',
        'question_type' => 'multiple_choice',
        'question_text' => 'Original?',
        'options' => ['A. one', 'B. two', 'C. three', 'D. four'],
        'answer_key' => 'A',
    ], questionItemAuthHeader($user))->assertCreated()->json('data');

    $this->putJson("/api/projects/{$project->id}/question-runs/{$questionRun->id}/items/{$created['id']}", [
        'question_text' => 'Updated?',
        'answer_key' => 'D',
    ], questionItemAuthHeader($user))
        ->assertOk()
        ->assertJsonPath('data.question_text', 'Updated?')
        ->assertJsonPath('data.answer_key', 'D');
});

test('faculty can delete a question item and sequences are renumbered', function () {
    $user = User::factory()->create();
    [$project, $questionRun] = seedQuestionRunFor($user);

    $first = $this->postJson("/api/projects/{$project->id}/question-runs/{$questionRun->id}/items", [
        'topic_name' => 'T1',
        'bloom_level' => 'knowledge',
        'question_type' => 'multiple_choice',
        'question_text' => 'Q1',
        'options' => ['A. a', 'B. b', 'C. c', 'D. d'],
        'answer_key' => 'A',
    ], questionItemAuthHeader($user))->json('data');

    $second = $this->postJson("/api/projects/{$project->id}/question-runs/{$questionRun->id}/items", [
        'topic_name' => 'T2',
        'bloom_level' => 'knowledge',
        'question_type' => 'multiple_choice',
        'question_text' => 'Q2',
        'options' => ['A. a', 'B. b', 'C. c', 'D. d'],
        'answer_key' => 'B',
    ], questionItemAuthHeader($user))->json('data');

    expect($first['sequence'])->toBe(1)
        ->and($second['sequence'])->toBe(2);

    $this->deleteJson(
        "/api/projects/{$project->id}/question-runs/{$questionRun->id}/items/{$first['id']}",
        [],
        questionItemAuthHeader($user)
    )->assertNoContent();

    $questionRun->refresh();
    expect($questionRun->generated_items)->toBe(1);

    $this->getJson("/api/projects/{$project->id}/question-runs/{$questionRun->id}", questionItemAuthHeader($user))
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.sequence', 1)
        ->assertJsonPath('data.items.0.question_text', 'Q2');
});

test('multiple choice validation rejects wrong option count', function () {
    $user = User::factory()->create();
    [$project, $questionRun] = seedQuestionRunFor($user);

    $this->postJson("/api/projects/{$project->id}/question-runs/{$questionRun->id}/items", [
        'topic_name' => 'Topic A',
        'bloom_level' => 'knowledge',
        'question_type' => 'multiple_choice',
        'question_text' => 'Q?',
        'options' => ['Only', 'Three'],
        'answer_key' => 'A',
    ], questionItemAuthHeader($user))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['options']);
});

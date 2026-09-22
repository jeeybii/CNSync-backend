<?php

use App\Models\StudentDeck;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('student can grade an essay in their own deck but not another student deck', function () {
    config()->set('services.gemini.api_key', 'test-key');
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"score":75,"comment":"Good start. Explain retransmission."}']]]]],
    ])]);

    $owner = User::factory()->student()->create();
    $other = User::factory()->student()->create();
    $deck = StudentDeck::query()->create([
        'user_id' => $owner->id,
        'title' => 'Networks',
        'status' => 'ready',
        'number_of_items' => 1,
        'questions' => [[
            'sequence' => 1,
            'question_type' => 'essay',
            'question_text' => 'Explain TCP reliability.',
            'answer_key' => 'Acknowledgements and retransmission.',
            'options' => [],
        ]],
    ]);
    $url = "/api/student/decks/{$deck->id}/grade-essay";

    $this->postJson($url, ['sequence' => 1, 'answer' => 'TCP acknowledges packets.'], [
        'Authorization' => 'Bearer '.$owner->createToken('test')->plainTextToken,
    ])->assertOk()->assertJsonPath('data.score', 75);

    auth()->forgetGuards();
    $this->postJson($url, ['sequence' => 1, 'answer' => 'TCP acknowledges packets.'], [
        'Authorization' => 'Bearer '.$other->createToken('test')->plainTextToken,
    ])->assertForbidden();
});

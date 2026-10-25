<?php

use App\Services\StudentDeckGeneratorService;
use Illuminate\Support\Facades\Http;

test('student deck generator accepts essay questions with a model answer', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ai.student_provider', 'gemini');
    config()->set('services.gemini.api_key', 'test-key');
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => json_encode([
            'questions' => [[
                'sequence' => 1,
                'question_text' => 'Explain how TCP provides reliable delivery.',
                'question_type' => 'essay',
                'options' => [],
                'answer_key' => 'TCP uses acknowledgements and retransmissions.',
                'hint' => 'Think about missing packets.',
            ]],
        ], JSON_THROW_ON_ERROR)]]]]],
    ])]);

    $questions = app(StudentDeckGeneratorService::class)->generate(['TCP is reliable via acknowledgements and retransmission.'], 1, ['essay' => 1]);

    expect($questions)->toHaveCount(1)
        ->and($questions[0]['question_type'])->toBe('essay')
        ->and($questions[0]['answer_key'])->toBe('TCP uses acknowledgements and retransmissions.');
});

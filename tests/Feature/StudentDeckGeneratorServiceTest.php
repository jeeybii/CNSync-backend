<?php

use App\Services\StudentDeckGeneratorService;
use Illuminate\Support\Facades\Http;

test('ollama student generation retries invalid items and enforces each requested type', function () {
    config()->set('services.ai.provider', 'ollama');
    config()->set('services.ai.student_provider', 'ollama');
    config()->set('services.ollama.base_url', 'http://100.73.128.62:11434');

    Http::fakeSequence()
        ->push(['response' => '{"items":[]}', 'done' => true])
        ->push([
            'response' => json_encode([
                'question_text' => 'Which device forwards packets between networks?',
                'options' => ['Router', 'Switch', 'Hub', 'Repeater'],
                'answer_key' => 'A',
                'hint' => 'It examines destination IP addresses.',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ])
        ->push([
            'response' => json_encode([
                'question_text' => 'A switch primarily forwards frames using MAC addresses.',
                'options' => ['True', 'False'],
                'answer_key' => 'True',
                'hint' => 'Think about Layer 2 addressing.',
            ], JSON_THROW_ON_ERROR),
            'done' => true,
        ]);

    $questions = app(StudentDeckGeneratorService::class)->generate(
        ['Routers forward packets using IP addresses. Switches forward frames using MAC addresses.'],
        2,
        ['multiple_choice' => 1, 'true_false' => 1],
    );

    expect($questions)->toHaveCount(2)
        ->and($questions[0]['question_type'])->toBe('multiple_choice')
        ->and($questions[1]['question_type'])->toBe('true_false');

    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'format.properties.answer_key.enum') === ['A', 'B', 'C', 'D']);
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'format.properties.answer_key.enum') === ['True', 'False']);
});

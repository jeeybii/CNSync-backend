<?php

use App\Services\StudentEssayGrader;
use Illuminate\Support\Facades\Http;

test('AI essay grading returns a bounded score and simple comment', function () {
    config()->set('services.gemini.api_key', 'test-key');
    Http::fake(['*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => '{"score":84,"comment":"Good explanation. Add one example."}']]]]],
    ])]);

    $grade = app(StudentEssayGrader::class)->grade('Explain TCP', 'Reliable transport protocol', 'TCP gives reliable delivery.');

    expect($grade)->toBe(['score' => 84, 'comment' => 'Good explanation. Add one example.']);
    Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'test-key'));
});

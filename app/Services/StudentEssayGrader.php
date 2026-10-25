<?php

namespace App\Services;

use RuntimeException;

class StudentEssayGrader
{
    /** @return array{score:int,comment:string} */
    public function grade(string $question, string $modelAnswer, string $studentAnswer): array
    {
        $prompt = implode("\n", [
            'Grade this student essay against the model answer. Return only JSON with score (integer 0-100) and comment (one or two simple, constructive sentences).',
            'Award partial credit for correct ideas expressed in different words. Ignore minor spelling and grammar errors.',
            'Question: '.$question,
            'Model answer: '.$modelAnswer,
            'Student answer: '.$studentAnswer,
        ]);

        $result = app(AiTextGenerator::class)->generateJson(
            $prompt,
            0.1,
            null,
            null,
            (string) config('services.ai.student_provider', 'gemini'),
        );
        if (! is_array($result) || ! is_numeric($result['score'] ?? null) || ! is_string($result['comment'] ?? null)) {
            throw new RuntimeException('AI returned an invalid essay grade. Please try again.');
        }

        return [
            'score' => max(0, min(100, (int) round((float) $result['score']))),
            'comment' => trim($result['comment']),
        ];
    }
}

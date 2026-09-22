<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class StudentEssayGrader
{
    /** @return array{score:int,comment:string} */
    public function grade(string $question, string $modelAnswer, string $studentAnswer): array
    {
        $key = (string) config('services.gemini.api_key');
        if (blank($key)) {
            throw new RuntimeException('Gemini API key is missing.');
        }

        $prompt = implode("\n", [
            'Grade this student essay against the model answer. Return only JSON with score (integer 0-100) and comment (one or two simple, constructive sentences).',
            'Award partial credit for correct ideas expressed in different words. Ignore minor spelling and grammar errors.',
            'Question: '.$question,
            'Model answer: '.$modelAnswer,
            'Student answer: '.$studentAnswer,
        ]);

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $model = (string) config('services.gemini.model');
        $response = Http::withHeaders(['x-goog-api-key' => $key])
            ->timeout(40)
            ->post(sprintf('%s/v1beta/models/%s:generateContent', $baseUrl, $model), [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json'],
            ]);
        $response->throw();

        $raw = data_get($response->json(), 'candidates.0.content.parts.0.text');
        $result = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($result) || ! is_numeric($result['score'] ?? null) || ! is_string($result['comment'] ?? null)) {
            throw new RuntimeException('AI returned an invalid essay grade. Please try again.');
        }

        return [
            'score' => max(0, min(100, (int) round((float) $result['score']))),
            'comment' => trim($result['comment']),
        ];
    }
}

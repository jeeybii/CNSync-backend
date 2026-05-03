<?php

namespace App\Services;

use App\Enums\BloomLevel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiBloomDistributionService
{
    /**
     * @param  array<int, array{name:string,hours:float|int,objective:?string,bloom_level:?string}>  $topics
     * @return array<string, float>
     */
    public function infer(array $topics): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if (blank($apiKey)) {
            throw new RuntimeException('Gemini API key is missing. Set GEMINI_API_KEY in .env.');
        }

        if (empty($topics)) {
            throw new RuntimeException('Cannot infer Bloom distribution without syllabus topics.');
        }

        $topicsText = collect($topics)
            ->map(fn (array $topic): string => sprintf(
                '- topic: %s | hours: %s | objective: %s | extracted_bloom: %s',
                $topic['name'],
                (string) $topic['hours'],
                $topic['objective'] ?? 'n/a',
                $topic['bloom_level'] ?? 'n/a',
            ))
            ->implode("\n");

        $prompt = implode("\n", [
            'Infer an exam Bloom distribution from syllabus topics.',
            'Return ONLY JSON with numeric keys: knowledge, understand, apply, analyze, evaluate, create.',
            'Each value must be >= 0. Values do not need to sum to 1.',
            'Heavily use objective verbs and topic depth/hours.',
            'Syllabus topics:',
            $topicsText,
        ]);

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $response = $this->callGeminiWithFallback($baseUrl, $apiKey, $prompt);
        $payload = $this->parseJsonPayload($response);

        $levels = BloomLevel::values();
        $resolved = [];
        foreach ($levels as $level) {
            $resolved[$level] = max(0.0, (float) ($payload[$level] ?? 0.0));
        }

        $sum = array_sum($resolved);
        if ($sum <= 0) {
            throw new RuntimeException('AI Bloom distribution is invalid (all zero).');
        }

        return array_map(static fn (float $value): float => $value / $sum, $resolved);
    }

    private function callGeminiWithFallback(string $baseUrl, string $apiKey, string $prompt): Response
    {
        $models = array_values(array_unique(array_filter([
            (string) config('services.gemini.model'),
            (string) config('services.gemini.fallback_model'),
        ])));

        $retries = max(1, (int) config('services.gemini.retries', 2));
        $sleepMs = max(200, (int) config('services.gemini.retry_sleep_ms', 400));
        $lastResponse = null;

        foreach ($models as $model) {
            $url = sprintf('%s/v1beta/models/%s:generateContent?key=%s', $baseUrl, $model, $apiKey);

            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                $response = Http::timeout(25)
                    ->connectTimeout(8)
                    ->post($url, [
                        'contents' => [[
                            'parts' => [['text' => $prompt]],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'responseMimeType' => 'application/json',
                        ],
                    ]);

                if ($response->successful()) {
                    return $response;
                }

                $lastResponse = $response;
                $isRetryable = in_array($response->status(), [429, 500, 502, 503, 504], true);
                if (! $isRetryable) {
                    break;
                }

                usleep($sleepMs * 1000 * $attempt);
            }
        }

        if ($lastResponse !== null) {
            $lastResponse->throw();
        }

        throw new RuntimeException('Gemini Bloom distribution request failed without a response.');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonPayload(Response $response): array
    {
        $jsonText = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($jsonText) || blank($jsonText)) {
            throw new RuntimeException('Gemini returned an empty Bloom distribution payload.');
        }

        /** @var mixed $parsed */
        $parsed = json_decode($jsonText, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('Gemini Bloom distribution response is not valid JSON.');
        }

        return $parsed;
    }
}

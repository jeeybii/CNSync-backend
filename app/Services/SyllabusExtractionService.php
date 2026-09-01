<?php

namespace App\Services;

use App\Enums\BloomLevel;
use App\Models\Document;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyllabusExtractionService
{
    /**
     * @return array<int, array{topic_name:string,hours:float,objective:?string,bloom_level:?string,confidence:?float}>
     */
    public function extract(Document $document): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if (blank($apiKey)) {
            throw new RuntimeException('Gemini API key is missing. Set GEMINI_API_KEY in .env.');
        }

        $chunkText = $document->extractedChunks()
            ->orderBy('chunk_index')
            ->get(['content', 'page_number'])
            ->map(fn ($chunk): string => sprintf(
                '[Page %s] %s',
                $chunk->page_number ?? 'n/a',
                $chunk->content,
            ))
            ->implode("\n\n");

        if (blank($chunkText)) {
            throw new RuntimeException('No extracted syllabus content found for AI extraction.');
        }

        $prompt = implode("\n", [
            'Extract syllabus topics into strict JSON.',
            'Return JSON object with key "topics".',
            'topics must be an array of objects with: topic_name, hours, objective, bloom_level, confidence.',
            'hours must be numeric and positive.',
            'bloom_level must be one of the Updated Bloom\'s Taxonomy levels: remembering, understanding, applying, analyzing, evaluating, creating.',
            'confidence must be 0 to 1.',
            'Do not include markdown, only JSON.',
            'Syllabus content:',
            $chunkText,
        ]);

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $response = $this->callGeminiWithFallback($baseUrl, $apiKey, $prompt);

        $jsonText = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($jsonText) || blank($jsonText)) {
            throw new RuntimeException('Gemini returned an empty syllabus extraction payload.');
        }

        /** @var mixed $parsed */
        $parsed = json_decode($jsonText, true);
        if (! is_array($parsed) || ! isset($parsed['topics']) || ! is_array($parsed['topics'])) {
            throw new RuntimeException('Syllabus extraction response is not valid JSON schema.');
        }

        $topics = [];
        foreach ($parsed['topics'] as $topic) {
            if (! is_array($topic)) {
                continue;
            }

            $name = $topic['topic_name'] ?? null;
            $hours = $topic['hours'] ?? null;

            if (! is_string($name) || blank($name) || ! is_numeric($hours) || (float) $hours <= 0) {
                continue;
            }

            $confidence = isset($topic['confidence']) && is_numeric($topic['confidence'])
                ? max(0.0, min(1.0, (float) $topic['confidence']))
                : null;

            $topics[] = [
                'topic_name' => trim($name),
                'hours' => (float) $hours,
                'objective' => isset($topic['objective']) && is_string($topic['objective'])
                    ? trim($topic['objective'])
                    : null,
                'bloom_level' => $this->normalizeBloomLevel($topic['bloom_level'] ?? null),
                'confidence' => $confidence,
            ];
        }

        if (empty($topics)) {
            throw new RuntimeException('Syllabus extraction did not produce any valid topic rows.');
        }

        return $topics;
    }

    private function normalizeBloomLevel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        $allowed = BloomLevel::values();

        return in_array($normalized, $allowed, true) ? $normalized : null;
    }

    private function callGeminiWithFallback(string $baseUrl, string $apiKey, string $prompt): Response
    {
        $models = array_values(array_unique(array_filter([
            (string) config('services.gemini.model'),
            (string) config('services.gemini.fallback_model'),
        ])));

        $retries = max(1, (int) config('services.gemini.retries', 5));
        $sleepMs = max(200, (int) config('services.gemini.retry_sleep_ms', 1500));
        $lastResponse = null;

        foreach ($models as $model) {
            $url = sprintf('%s/v1beta/models/%s:generateContent', $baseUrl, $model);

            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                    ->timeout(40)
                    ->connectTimeout(10)
                    ->post($url, [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
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

        throw new RuntimeException('Gemini request failed without a response.');
    }
}

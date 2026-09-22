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
     * @return array<int, array{topic_name:string,hours:float,objective:?string,bloom_level:?string,confidence:?float,term:string,week_number:int}>
     */
    public function extract(Document $document): array
    {
        return $this->extractWithSuggestions($document)['topics'];
    }

    /**
     * @return array{topics:array<int, array<string, mixed>>, suggested_bloom_levels:array<int, string>, suggested_bloom_levels_by_term:array<string, array<int, string>>, suggested_question_type_percentages_by_term:array<string, array<string, int>>}
     */
    public function extractWithSuggestions(Document $document): array
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
            'Return a JSON object with keys "topics", "suggested_bloom_levels_by_term", and "suggested_question_type_percentages_by_term".',
            'Give every topic its own most appropriate bloom_level based on its objective and context.',
            'suggested_bloom_levels_by_term must contain midterm and final arrays of useful Bloom levels; include at least three distinct levels per term, adding suitable levels when topic levels contain fewer than three.',
            'suggested_question_type_percentages_by_term must contain midterm and final objects, each with numeric percentages for multiple_choice, true_false, identification, and essay that sum to 100.',
            'Recommend question-type percentages separately for each term using its topic objectives/context and the suggested Bloom levels; do not use the same generic mix for both terms unless the content warrants it.',
            'topics must be top-level syllabus topics only, not their subtopics.',
            'Keep topics in exactly the same reading order as the syllabus; do not alphabetize, group, or reorder them by term or week.',
            'Each topic must have: topic_name, hours, objective, bloom_level, confidence, term, week_number.',
            'hours must be numeric and positive.',
            'bloom_level must be one of the Updated Bloom\'s Taxonomy levels: remembering, understanding, applying, analyzing, evaluating, creating.',
            'confidence must be 0 to 1.',
            'term is midterm or final. Follow explicit MIDTERM and FINAL headings when present.',
            'If no term headings exist, split the top-level topics into two sequential halves.',
            'Use explicit week numbers when present. Otherwise assign one sequential week per top-level topic; subtopics do not create weeks.',
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
                'bloom_level' => $this->normalizeBloomLevel($topic['bloom_level'] ?? null) ?? 'understanding',
                'confidence' => $confidence,
                'term' => in_array(strtolower((string) ($topic['term'] ?? '')), ['midterm', 'final'], true)
                    ? strtolower($topic['term'])
                    : null,
                'week_number' => filter_var($topic['week_number'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: count($topics) + 1,
            ];
        }

        if (empty($topics)) {
            throw new RuntimeException('Syllabus extraction did not produce any valid topic rows.');
        }

        $hasTermHeadings = preg_match('/\bmidterms?\b/i', $chunkText) === 1
            && preg_match('/\bfinals?\b/i', $chunkText) === 1;
        $midpoint = (int) ceil(count($topics) / 2);
        $usedWeeks = [];
        foreach ($topics as $index => &$topic) {
            if (! $hasTermHeadings || $topic['term'] === null) {
                $topic['term'] = $index < $midpoint ? 'midterm' : 'final';
            }
            if (in_array($topic['week_number'], $usedWeeks, true)) {
                $topic['week_number'] = max($usedWeeks) + 1;
            }
            $usedWeeks[] = $topic['week_number'];
        }
        unset($topic);

        $suggestionService = app(ExamSuggestionService::class);
        $bloomByTerm = [];
        $questionTypesByTerm = [];
        foreach (['midterm', 'final'] as $term) {
            $termTopics = array_values(array_filter($topics, fn (array $topic): bool => $topic['term'] === $term));
            $rawLevels = data_get($parsed, "suggested_bloom_levels_by_term.$term", []);
            $bloomByTerm[$term] = $suggestionService->bloomLevels(
                $termTopics,
                is_array($rawLevels) ? $rawLevels : [],
            );

            $rawPercentages = data_get($parsed, "suggested_question_type_percentages_by_term.$term");
            $questionTypesByTerm[$term] = $suggestionService->questionTypePercentages(
                is_array($rawPercentages) ? $rawPercentages : null,
                $termTopics,
            );
        }

        return [
            'topics' => $topics,
            'suggested_bloom_levels' => array_slice(array_values(array_unique(array_merge($bloomByTerm['midterm'], $bloomByTerm['final']))), 0, 3),
            'suggested_bloom_levels_by_term' => $bloomByTerm,
            'suggested_question_type_percentages_by_term' => $questionTypesByTerm,
        ];
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

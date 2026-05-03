<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiQuestionGenerator
{
    /**
     * @param  array<int, array{content:string,page_number:int|null,document_id:int}>  $contexts
     * @return array{question_text:string,options:array<int, string>,answer_key:string,citations:array<int, array<string, mixed>>}
     */
    public function generateOne(string $topicName, string $bloomLevel, string $questionType, array $contexts): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if (blank($apiKey)) {
            throw new RuntimeException('Gemini API key is missing. Set GEMINI_API_KEY in .env.');
        }

        $contextText = collect($contexts)
            ->map(fn (array $chunk): string => sprintf(
                '[Doc %d | Page %s] %s',
                $chunk['document_id'],
                $chunk['page_number'] ?? 'n/a',
                $chunk['content'],
            ))
            ->implode("\n\n");

        $prompt = implode("\n", [
            'Generate exactly one grounded objective question as JSON only.',
            'Use only the provided context and avoid external knowledge.',
            sprintf('Topic: %s', $topicName),
            sprintf('Bloom level: %s', $bloomLevel),
            sprintf('Question type: %s', $questionType),
            'Return JSON object with keys: question_text, options, answer_key.',
            'For multiple_choice: options must have exactly 4 strings and answer_key must be one of A,B,C,D.',
            'For true_false: options must be exactly ["True","False"] and answer_key must be either True or False.',
            'Context:',
            $contextText,
        ]);

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $response = $this->callGeminiWithFallback($baseUrl, $apiKey, $prompt);
        $parsed = $this->parseJsonPayload($response);
        $validated = $this->validateQuestionPayload($parsed, $questionType);

        return [
            'question_text' => $validated['question_text'],
            'options' => $validated['options'],
            'answer_key' => $validated['answer_key'],
            'citations' => collect($contexts)
                ->map(fn (array $chunk): array => [
                    'document_id' => $chunk['document_id'],
                    'page_number' => $chunk['page_number'],
                ])
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, array{id:string,topic_name:string,bloom_level:string,question_type:string}>  $requests
     * @param  array<string, array<int, array{content:string,page_number:int|null,document_id:int}>>  $contextsByRequestId
     * @return array<string, array{question_text:string,options:array<int, string>,answer_key:string}>
     */
    public function generateBatch(array $requests, array $contextsByRequestId): array
    {
        $apiKey = (string) config('services.gemini.api_key');
        if (blank($apiKey)) {
            throw new RuntimeException('Gemini API key is missing. Set GEMINI_API_KEY in .env.');
        }

        if (empty($requests)) {
            return [];
        }

        $requestSpecs = collect($requests)
            ->map(fn (array $request): string => sprintf(
                '- id=%s | topic=%s | bloom=%s',
                $request['id'],
                $request['topic_name'],
                $request['bloom_level'],
            ).sprintf(' | question_type=%s', $request['question_type']))
            ->implode("\n");

        $contextText = collect($contextsByRequestId)
            ->map(function (array $contexts, string $requestId): string {
                $chunks = collect($contexts)
                    ->map(fn (array $chunk): string => sprintf(
                        '[Doc %d | Page %s] %s',
                        $chunk['document_id'],
                        $chunk['page_number'] ?? 'n/a',
                        $chunk['content'],
                    ))
                    ->implode("\n");

                return sprintf("Request %s context:\n%s", $requestId, $chunks);
            })
            ->implode("\n\n");

        $prompt = implode("\n", [
            'Generate grounded objective questions as JSON only.',
            'Use only the provided context and avoid external knowledge.',
            'Return JSON object with key "items".',
            'items must be an array where each item has:',
            'request_id, question_text, question_type, options, answer_key.',
            'For multiple_choice: options must have exactly 4 strings and answer_key must be one of A,B,C,D.',
            'For true_false: options must be exactly ["True","False"] and answer_key must be either True or False.',
            'Generate exactly one item for every request_id listed below.',
            'Requests:',
            $requestSpecs,
            'Context:',
            $contextText,
        ]);

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $response = $this->callGeminiWithFallback($baseUrl, $apiKey, $prompt);
        $parsed = $this->parseJsonPayload($response);

        $items = $parsed['items'] ?? null;
        if (! is_array($items)) {
            throw new RuntimeException('Gemini batch response is missing an items array.');
        }

        $byId = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['request_id']) || ! is_string($item['request_id'])) {
                continue;
            }

            try {
                $validated = $this->validateQuestionPayload($item, $item['question_type'] ?? null);
            } catch (RuntimeException) {
                continue;
            }

            $byId[$item['request_id']] = $validated;
        }

        return $byId;
    }

    /**
     * @param  array<int, Document>  $documents
     * @return array<int, array{content:string,page_number:int|null,document_id:int}>
     */
    public function retrieveContext(string $topicName, array $documents, int $limit = 3): array
    {
        $needle = mb_strtolower($topicName);
        $matched = [];
        $fallback = [];

        foreach ($documents as $document) {
            $chunks = $document->relationLoaded('extractedChunks')
                ? $document->extractedChunks->sortBy('chunk_index')->values()
                : $document->extractedChunks()->orderBy('chunk_index')->get(['document_id', 'content', 'page_number']);

            foreach ($chunks as $chunk) {
                $payload = [
                    'document_id' => $chunk->document_id,
                    'content' => $chunk->content,
                    'page_number' => $chunk->page_number,
                ];

                if (str_contains(mb_strtolower($chunk->content), $needle)) {
                    $matched[] = $payload;
                } else {
                    $fallback[] = $payload;
                }
            }
        }

        return array_slice(
            ! empty($matched) ? $matched : $fallback,
            0,
            $limit,
        );
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
            $url = sprintf('%s/v1beta/models/%s:generateContent?key=%s', $baseUrl, $model, $apiKey);

            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                $response = Http::timeout(30)
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
                            'temperature' => 0.2,
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

    /**
     * @return array<string, mixed>
     */
    private function parseJsonPayload(Response $response): array
    {
        $jsonText = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($jsonText) || blank($jsonText)) {
            throw new RuntimeException('Gemini returned an empty response payload.');
        }

        /** @var mixed $parsed */
        $parsed = json_decode($jsonText, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('Gemini response is not valid JSON.');
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{question_text:string,options:array<int, string>,answer_key:string}
     */
    private function validateQuestionPayload(array $payload, ?string $requestedType = null): array
    {
        $questionText = $payload['question_text'] ?? null;
        $options = $payload['options'] ?? null;
        $answerKey = $payload['answer_key'] ?? null;
        $questionType = (string) ($payload['question_type'] ?? $requestedType ?? 'multiple_choice');

        if (! is_string($questionText) || ! is_array($options) || ! is_string($answerKey)) {
            throw new RuntimeException('Gemini response failed schema validation.');
        }

        if ($questionType === 'true_false') {
            $normalizedOptions = array_values(array_map('strval', $options));
            if ($normalizedOptions !== ['True', 'False']) {
                throw new RuntimeException('True/False questions must use options ["True","False"].');
            }

            if (! in_array($answerKey, ['True', 'False'], true)) {
                throw new RuntimeException('True/False answer key must be True or False.');
            }
        } else {
            if (count($options) !== 4) {
                throw new RuntimeException('Multiple-choice questions must include exactly 4 options.');
            }

            $allowedAnswerKeys = ['A', 'B', 'C', 'D'];
            if (! in_array($answerKey, $allowedAnswerKeys, true)) {
                throw new RuntimeException('Gemini answer key must be one of A, B, C, or D.');
            }
        }

        return [
            'question_text' => $questionText,
            'options' => array_values(array_map('strval', $options)),
            'answer_key' => $answerKey,
        ];
    }
}

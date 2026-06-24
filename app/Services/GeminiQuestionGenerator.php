<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\Document;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiQuestionGenerator
{
    /**
     * @param  array<int, array{content:string,page_number:int|null,document_id:int}>  $contexts
     * @return array{question_text:string,options:mixed,answer_key:string,citations:array<int, array<string, mixed>>}
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
            ...$this->sourceRestrictionRules(),
            sprintf('Topic: %s', $topicName),
            sprintf('Bloom level (Updated Bloom\'s Taxonomy): %s', $bloomLevel),
            sprintf('Question type: %s', $questionType),
            'Return a JSON object with keys: question_text, options, answer_key.',
            ...$this->questionTypeInstructions(),
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
     * @return array<string, array{question_text:string,options:mixed,answer_key:string}>
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
                '- id=%s | topic=%s | bloom=%s | question_type=%s',
                $request['id'],
                $request['topic_name'],
                $request['bloom_level'],
                $request['question_type'],
            ))
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
            ...$this->sourceRestrictionRules(),
            'Return a JSON object with key "items".',
            'items must be an array; each item has: request_id, question_text, question_type, options, answer_key.',
            ...$this->questionTypeInstructions(),
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

    /**
     * Returns true if at least one material chunk explicitly mentions the topic name.
     * Used to decide whether to supplement context with a syllabus topic guide.
     *
     * @param  array<int, Document>  $documents
     */
    public function hasTopicMatch(string $topicName, array $documents): bool
    {
        $needle = mb_strtolower($topicName);

        foreach ($documents as $document) {
            $chunks = $document->relationLoaded('extractedChunks')
                ? $document->extractedChunks
                : $document->extractedChunks()->get(['content']);

            foreach ($chunks as $chunk) {
                if (str_contains(mb_strtolower($chunk->content), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Shared source-restriction rules injected into every generation prompt.
     *
     * @return array<int, string>
     */
    private function sourceRestrictionRules(): array
    {
        return [
            'Source restriction: Use ONLY the provided learning material context below.',
            'NEVER ask about course titles, course codes, instructor names, course policies, grading systems, or any administrative/syllabus metadata.',
            'Questions must test subject-matter knowledge found in the learning materials.',
            'Some context chunks may be prefixed with [TOPIC GUIDE]. These are syllabus topic descriptions, NOT learning material.',
            '  - Use [TOPIC GUIDE] chunks only to understand what subject area the question must cover.',
            '  - Do NOT quote or reference anything from a [TOPIC GUIDE] chunk directly in the question.',
            '  - If only [TOPIC GUIDE] context is available, generate a plausible educational question that a',
            '    student studying this topic would need to know — based on the topic name and scope, not on the guide text.',
        ];
    }

    /**
     * Shared per-type formatting instructions injected into every prompt.
     *
     * @return array<int, string>
     */
    private function questionTypeInstructions(): array
    {
        return [
            'Question type rules:',
            '- multiple_choice: options = exactly 4 strings; answer_key = one of A, B, C, D.',
            '- true_false: options = ["True","False"]; answer_key = "True" or "False".',
            '- identification: options = [] (empty array); answer_key = the exact expected answer text.',
            '- essay: options = [] (empty array); answer_key = model answer or scoring criteria.',
            '- matching_type: options = {"column_a": [...], "column_b": [...]} with 3–5 equal-length string arrays;',
            '  answer_key = comma-separated pairs like "1-A,2-C,3-B" mapping 1-indexed column_a to column_b letter.',
        ];
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
     * @return array{question_text:string,options:mixed,answer_key:string}
     */
    private function validateQuestionPayload(array $payload, ?string $requestedType = null): array
    {
        $questionText = $payload['question_text'] ?? null;
        $options = $payload['options'] ?? null;
        $answerKey = $payload['answer_key'] ?? null;
        $questionType = (string) ($payload['question_type'] ?? $requestedType ?? QuestionType::MultipleChoice->value);

        if (! is_string($questionText) || blank($questionText) || ! is_string($answerKey)) {
            throw new RuntimeException('Gemini response failed schema validation.');
        }

        return match ($questionType) {
            QuestionType::MultipleChoice->value => $this->validateMultipleChoicePayload($payload, $options, $answerKey),
            QuestionType::TrueFalse->value => $this->validateTrueFalsePayload($payload, $options, $answerKey),
            QuestionType::Identification->value, QuestionType::Essay->value => $this->validateOpenEndedPayload($payload, $answerKey),
            QuestionType::MatchingType->value => $this->validateMatchingTypePayload($payload, $options, $answerKey),
            default => throw new RuntimeException(sprintf('Unsupported question type: %s.', $questionType)),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{question_text:string,options:mixed,answer_key:string}
     */
    private function validateMultipleChoicePayload(array $payload, mixed $options, string $answerKey): array
    {
        if (! is_array($options) || count($options) !== 4) {
            throw new RuntimeException('Multiple-choice questions must include exactly 4 options.');
        }
        if (! in_array($answerKey, ['A', 'B', 'C', 'D'], true)) {
            throw new RuntimeException('Gemini answer key must be one of A, B, C, or D.');
        }

        return [
            'question_text' => (string) $payload['question_text'],
            'options' => array_values(array_map('strval', $options)),
            'answer_key' => $answerKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{question_text:string,options:mixed,answer_key:string}
     */
    private function validateTrueFalsePayload(array $payload, mixed $options, string $answerKey): array
    {
        if (! is_array($options)) {
            throw new RuntimeException('True/False questions must provide an options array.');
        }
        $normalized = array_values(array_map('strval', $options));
        if ($normalized !== ['True', 'False']) {
            throw new RuntimeException('True/False questions must use options ["True","False"].');
        }
        if (! in_array($answerKey, ['True', 'False'], true)) {
            throw new RuntimeException('True/False answer key must be True or False.');
        }

        return [
            'question_text' => (string) $payload['question_text'],
            'options' => ['True', 'False'],
            'answer_key' => $answerKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{question_text:string,options:mixed,answer_key:string}
     */
    private function validateOpenEndedPayload(array $payload, string $answerKey): array
    {
        if (blank($answerKey)) {
            throw new RuntimeException('Identification/Essay answer key must not be empty.');
        }

        return [
            'question_text' => (string) $payload['question_text'],
            'options' => [],
            'answer_key' => $answerKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{question_text:string,options:mixed,answer_key:string}
     */
    private function validateMatchingTypePayload(array $payload, mixed $options, string $answerKey): array
    {
        if (! is_array($options) || ! isset($options['column_a'], $options['column_b'])) {
            throw new RuntimeException('Matching type must include column_a and column_b in options.');
        }
        $colA = $options['column_a'];
        $colB = $options['column_b'];
        if (! is_array($colA) || ! is_array($colB) || count($colA) !== count($colB)) {
            throw new RuntimeException('Matching type column_a and column_b must be equal-length arrays.');
        }
        $count = count($colA);
        if ($count < 3 || $count > 10) {
            throw new RuntimeException('Matching type must have between 3 and 10 pairs.');
        }
        if (blank($answerKey)) {
            throw new RuntimeException('Matching type answer key must not be empty.');
        }

        return [
            'question_text' => (string) $payload['question_text'],
            'options' => [
                'column_a' => array_values(array_map('strval', $colA)),
                'column_b' => array_values(array_map('strval', $colB)),
            ],
            'answer_key' => $answerKey,
        ];
    }
}

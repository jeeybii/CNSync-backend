<?php

namespace App\Services;

use App\Models\Document;
use App\Models\TosAllocation;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiQuestionGenerator
{
    /**
     * @param  array<int, array{content:string,page_number:int|null,document_id:int}>  $contexts
     * @return array{question_text:string,options:array<int, string>,answer_key:string,citations:array<int, array<string, mixed>>}
     */
    public function generate(TosAllocation $allocation, array $contexts): array
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
            'Generate exactly one grounded multiple-choice question as JSON only.',
            'Use only the provided context and avoid external knowledge.',
            sprintf('Topic: %s', $allocation->topic_name),
            sprintf('Bloom level: %s', $allocation->bloom_level->value),
            'Return JSON object with keys: question_text, options, answer_key.',
            'options must be an array of 4 strings.',
            'answer_key must be one of A,B,C,D.',
            'Context:',
            $contextText,
        ]);

        $model = (string) config('services.gemini.model');
        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $url = sprintf('%s/v1beta/models/%s:generateContent?key=%s', $baseUrl, $model, $apiKey);

        $response = Http::timeout(30)
            ->connectTimeout(10)
            ->retry(2, 500)
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

        $response->throw();

        $jsonText = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($jsonText) || blank($jsonText)) {
            throw new RuntimeException('Gemini returned an empty response payload.');
        }

        /** @var mixed $parsed */
        $parsed = json_decode($jsonText, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('Gemini response is not valid JSON.');
        }

        $questionText = $parsed['question_text'] ?? null;
        $options = $parsed['options'] ?? null;
        $answerKey = $parsed['answer_key'] ?? null;

        if (! is_string($questionText) || ! is_array($options) || count($options) !== 4 || ! is_string($answerKey)) {
            throw new RuntimeException('Gemini response failed schema validation.');
        }

        $allowedAnswerKeys = ['A', 'B', 'C', 'D'];
        if (! in_array($answerKey, $allowedAnswerKeys, true)) {
            throw new RuntimeException('Gemini answer key must be one of A, B, C, or D.');
        }

        return [
            'question_text' => $questionText,
            'options' => array_values(array_map('strval', $options)),
            'answer_key' => $answerKey,
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
     * @param  array<int, Document>  $documents
     * @return array<int, array{content:string,page_number:int|null,document_id:int}>
     */
    public function retrieveContext(string $topicName, array $documents, int $limit = 3): array
    {
        $needle = mb_strtolower($topicName);
        $matched = [];
        $fallback = [];

        foreach ($documents as $document) {
            $chunks = $document->extractedChunks()
                ->orderBy('chunk_index')
                ->get(['document_id', 'content', 'page_number']);

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
}

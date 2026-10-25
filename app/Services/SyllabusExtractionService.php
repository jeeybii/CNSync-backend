<?php

namespace App\Services;

use App\Enums\BloomLevel;
use App\Models\Document;
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
            'suggested_question_type_percentages_by_term must contain midterm and final objects, each with numeric percentages for multiple_choice, true_false, identification, essay, and matching_type that sum to 100.',
            'Use this Bloom-to-question-type policy: remembering = identification, multiple_choice, matching_type; understanding = multiple_choice, matching_type, true_false; applying = multiple_choice, essay; analyzing = multiple_choice, essay; evaluating = essay, multiple_choice; creating = essay.',
            'Recommend question-type percentages separately for each term using its topic objectives/context and the suggested Bloom levels; do not use the same generic mix for both terms unless the content warrants it.',
            'topics must be top-level syllabus topics only, not their subtopics.',
            'topic_name must contain only the concise topic title (maximum 150 characters). Never put analysis, explanations, or extraction notes in topic_name.',
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

        $parsed = app(AiTextGenerator::class)->generateJson(
            $prompt,
            0.1,
            $this->responseSchema(),
            null,
            (string) config('services.ai.syllabus_provider', 'gemini'),
        );
        if (! is_array($parsed) || ! isset($parsed['topics']) || ! is_array($parsed['topics'])) {
            throw new RuntimeException('Syllabus extraction response is not valid JSON schema.');
        }

        $topics = [];
        foreach ($parsed['topics'] as $topic) {
            if (! is_array($topic)) {
                continue;
            }

            $name = $this->sanitizeTopicName($topic['topic_name'] ?? null);
            $hours = $topic['hours'] ?? null;

            if ($name === null || ! is_numeric($hours) || (float) $hours <= 0) {
                continue;
            }

            $confidence = isset($topic['confidence']) && is_numeric($topic['confidence'])
                ? max(0.0, min(1.0, (float) $topic['confidence']))
                : null;

            $topics[] = [
                'topic_name' => $name,
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

    private function sanitizeTopicName(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Some models occasionally append their reasoning after a large block of
        // whitespace. Keep the title before that boundary and discard the leak.
        $name = preg_split('/\s{4,}/u', trim($value), 2)[0] ?? '';
        $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';

        if ($name === '' || mb_strlen($name) > 255) {
            return null;
        }

        return $name;
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        $questionTypePercentages = [
            'type' => 'OBJECT',
            'properties' => array_fill_keys(
                ExamSuggestionService::QUESTION_TYPES,
                ['type' => 'NUMBER'],
            ),
            'required' => ExamSuggestionService::QUESTION_TYPES,
        ];

        return [
            'type' => 'OBJECT',
            'properties' => [
                'topics' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'topic_name' => ['type' => 'STRING'],
                            'hours' => ['type' => 'NUMBER'],
                            'objective' => ['type' => 'STRING', 'nullable' => true],
                            'bloom_level' => ['type' => 'STRING', 'enum' => BloomLevel::values()],
                            'confidence' => ['type' => 'NUMBER', 'minimum' => 0, 'maximum' => 1],
                            'term' => ['type' => 'STRING', 'enum' => ['midterm', 'final']],
                            'week_number' => ['type' => 'INTEGER', 'minimum' => 1],
                        ],
                        'required' => ['topic_name', 'hours', 'bloom_level', 'term', 'week_number'],
                    ],
                ],
                'suggested_bloom_levels_by_term' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'midterm' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING', 'enum' => BloomLevel::values()]],
                        'final' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING', 'enum' => BloomLevel::values()]],
                    ],
                    'required' => ['midterm', 'final'],
                ],
                'suggested_question_type_percentages_by_term' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'midterm' => $questionTypePercentages,
                        'final' => $questionTypePercentages,
                    ],
                    'required' => ['midterm', 'final'],
                ],
            ],
            'required' => [
                'topics',
                'suggested_bloom_levels_by_term',
                'suggested_question_type_percentages_by_term',
            ],
        ];
    }
}

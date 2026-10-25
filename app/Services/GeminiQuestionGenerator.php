<?php

namespace App\Services;

use App\Enums\QuestionType;
use App\Models\Document;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiQuestionGenerator
{
    /**
     * @param  array<int, array{content:string,page_number:int|null,document_id:int}>  $contexts
     * @return array{question_text:string,options:mixed,answer_key:string,citations:array<int, array<string, mixed>>}
     */
    public function generateOne(
        string $topicName,
        string $bloomLevel,
        string $questionType,
        array $contexts,
        array $avoidQuestions = [],
        ?string $providerOverride = null,
    ): array {
        $avoidedItems = $this->normalizeAvoidItems($avoidQuestions);
        $avoidDescriptions = array_map(function (array $item): string {
            $description = $item['question_text'];
            if (($item['question_type'] ?? null) === QuestionType::MatchingType->value && is_array($item['options'] ?? null)) {
                $description .= ' | terms: '.implode(', ', $item['options']['column_a'] ?? []);
            }

            return $description;
        }, $avoidedItems);

        $contextText = collect($contexts)
            ->map(fn (array $chunk): string => sprintf(
                '[Doc %d | Page %s] %s',
                $chunk['document_id'],
                $chunk['page_number'] ?? 'n/a',
                $chunk['content'],
            ))
            ->implode("\n\n");

        $basePrompt = implode("\n", [
            'Generate exactly one grounded objective question as JSON only.',
            ...$this->sourceRestrictionRules(),
            sprintf('Topic: %s', $topicName),
            sprintf('Bloom level (Updated Bloom\'s Taxonomy): %s', $bloomLevel),
            sprintf('Question type: %s', $questionType),
            'Quality requirements:',
            '- The question must directly assess the named topic and requested Bloom level.',
            '- Verify factual accuracy and the answer key before responding.',
            '- Multiple choice must have exactly one unequivocally correct option; distractors must be clearly incorrect and non-overlapping.',
            '- Do not repeat a question or test the same fact as any avoided question.',
            '- Do not include A./B./C./D. or 1./2./3. labels inside option text.',
            '- True/False must be a declarative statement, never a Which/What/How question.',
            '- Matching columns must contain genuinely corresponding terms and definitions.',
            empty($avoidDescriptions) ? 'Avoided questions: none.' : "Avoided questions:\n- ".implode("\n- ", $avoidDescriptions),
            'Return a JSON object with keys: question_text, options, answer_key.',
            ...$this->questionTypeInstructions(),
            'Context:',
            $contextText,
        ]);

        $provider = $providerOverride !== null
            ? strtolower($providerOverride)
            : (in_array($questionType, [QuestionType::MatchingType->value, QuestionType::Essay->value], true)
                ? 'gemini'
                : strtolower((string) config('services.ai.provider', 'gemini')));
        $isOllama = $provider === 'ollama';
        $attempts = $isOllama ? 5 : (($providerOverride !== null || $questionType === QuestionType::MatchingType->value) ? 3 : 1);
        $lastError = null;
        $bestDuplicate = null;
        $bestDuplicateScore = INF;
        $angles = ['definition', 'comparison', 'real-world scenario', 'troubleshooting case', 'cause-and-effect consequence'];
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $prompt = $basePrompt;
            if ($attempts > 1) {
                $prompt .= sprintf(
                    "\nVariation %d: use a %s angle and test a different concept from earlier items.",
                    $attempt,
                    $angles[$attempt - 1],
                );
            }
            if ($lastError !== null) {
                $prompt .= "\nThe previous response was rejected: {$lastError}\nGenerate a different corrected question.";
            }

            try {
                $parsed = app(AiTextGenerator::class)->generateJson(
                    $prompt,
                    $isOllama ? min(0.8, 0.2 + (($attempt - 1) * 0.15)) : 0.2,
                    $this->singleQuestionSchema($questionType),
                    null,
                    $provider,
                );
                $validated = $this->validateQuestionPayload($parsed, $questionType);
                $duplicateScore = $this->maximumCandidateSimilarity($validated, $questionType, $avoidedItems);
                if ($duplicateScore >= 0.72 && $duplicateScore < $bestDuplicateScore) {
                    $bestDuplicate = $validated;
                    $bestDuplicateScore = $duplicateScore;
                }
                $this->assertQuestionQuality($validated, $questionType, $avoidedItems);
                $lastError = null;
                break;
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
            }
        }

        if ($lastError === 'The question duplicates an existing exam item.'
            && $bestDuplicate !== null
            && $bestDuplicateScore < 0.82
            && $questionType !== QuestionType::MatchingType->value
            && ! $this->hasExactQuestionDuplicate($bestDuplicate, $avoidedItems)) {
            $validated = $bestDuplicate;
            $lastError = null;
            Log::warning('Accepted least-similar AI question after duplicate retries.', [
                'topic' => $topicName,
                'bloom_level' => $bloomLevel,
                'question_type' => $questionType,
                'similarity' => $bestDuplicateScore,
            ]);
        }

        if ($lastError !== null || ! isset($validated)) {
            throw new RuntimeException('AI could not produce a valid question after quality checks: '.$lastError);
        }

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
        if (empty($requests)) {
            return [];
        }

        if (strtolower((string) config('services.ai.provider', 'gemini')) === 'ollama') {
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

        $parsed = app(AiTextGenerator::class)->generateJson($prompt, 0.2);

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
            'Primary source: use the provided context below. It may contain learning materials or syllabus topic content.',
            'STRICT PROHIBITION — never generate questions about any of the following:',
            '  - The purpose, structure, or goals of a course orientation or introduction class.',
            '  - Course titles, course codes, instructor names, grading policies, course schedules.',
            '  - Institutional rules, policies, expectations, or syllabi.',
            '  - What a course orientation IS or what it aims to achieve.',
            '  Even if the topic is named "Course Orientation" or "Introduction", generate a question',
            '  about the TECHNICAL SUBJECT MATTER of the course (e.g. networking, programming, etc.),',
            '  NOT about the administrative orientation session itself.',
            'If the context is sufficient, base questions strictly on it.',
            'If the context is insufficient for the required question type or Bloom level, supplement with',
            '  your subject-matter knowledge about the topic to produce a valid, educationally sound question.',
            '  Do NOT fail — always generate a complete, properly formatted question.',
            'Some context chunks may be prefixed with [TOPIC GUIDE]. Use them to understand the technical subject area and intended scope.',
            'Never ask about the syllabus document, course administration, or the existence/wording of a topic guide itself.',
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
            '- essay: options = [] (empty array); answer_key = a complete model essay written as 1–3 cohesive prose paragraphs.',
            '  Do not format an essay answer_key as a numbered list, bullet list, outline, headings, or grading rubric.',
            '- matching_type: options = {"column_a": [...], "column_b": [...]} with 2–5 equal-length string arrays;',
            '  answer_key = comma-separated pairs like "1-A,2-C,3-B" mapping 1-indexed column_a to column_b letter.',
            '  IMPORTANT for matching_type: if the provided context does not contain enough distinct concepts',
            '  to form pairs, supplement with your own subject-matter knowledge about the topic to complete',
            '  the pairs. Never return fewer than 2 pairs — use topic knowledge to fill gaps.',
        ];
    }

    /** @return array<string, mixed> */
    private function singleQuestionSchema(string $questionType): array
    {
        $optionsSchema = match ($questionType) {
            QuestionType::MultipleChoice->value => [
                'type' => 'ARRAY',
                'items' => ['type' => 'STRING'],
                'minItems' => 4,
                'maxItems' => 4,
            ],
            QuestionType::TrueFalse->value => [
                'type' => 'ARRAY',
                'items' => ['type' => 'STRING', 'enum' => ['True', 'False']],
                'minItems' => 2,
                'maxItems' => 2,
            ],
            QuestionType::MatchingType->value => [
                'type' => 'OBJECT',
                'properties' => [
                    'column_a' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    'column_b' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                ],
                'required' => ['column_a', 'column_b'],
            ],
            default => [
                'type' => 'ARRAY',
                'items' => ['type' => 'STRING'],
                'minItems' => 0,
                'maxItems' => 0,
            ],
        };

        $answerKeySchema = match ($questionType) {
            QuestionType::MultipleChoice->value => ['type' => 'STRING', 'enum' => ['A', 'B', 'C', 'D']],
            QuestionType::TrueFalse->value => ['type' => 'STRING', 'enum' => ['True', 'False']],
            default => ['type' => 'STRING'],
        };

        return [
            'type' => 'OBJECT',
            'properties' => [
                'question_text' => ['type' => 'STRING'],
                'options' => $optionsSchema,
                'answer_key' => $answerKeySchema,
            ],
            'required' => ['question_text', 'options', 'answer_key'],
        ];
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
        $questionType = (string) ($requestedType ?? $payload['question_type'] ?? QuestionType::MultipleChoice->value);

        if (! is_string($questionText) || blank($questionText)) {
            throw new RuntimeException('AI response failed question schema validation.');
        }
        if ($questionType !== QuestionType::TrueFalse->value && ! is_string($answerKey)) {
            throw new RuntimeException('AI response failed answer-key schema validation.');
        }

        return match ($questionType) {
            QuestionType::MultipleChoice->value => $this->validateMultipleChoicePayload($payload, $options, (string) $answerKey),
            QuestionType::TrueFalse->value => $this->validateTrueFalsePayload($payload, $options, $answerKey),
            QuestionType::Identification->value, QuestionType::Essay->value => $this->validateOpenEndedPayload(
                $payload,
                (string) $answerKey,
                $questionType,
            ),
            QuestionType::MatchingType->value => $this->validateMatchingTypePayload($payload, $options, (string) $answerKey),
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
        $answerKey = strtoupper(trim($answerKey));
        if (! in_array($answerKey, ['A', 'B', 'C', 'D'], true)) {
            throw new RuntimeException('AI answer key must be one of A, B, C, or D.');
        }

        $normalizedOptions = array_values(array_map(
            fn (mixed $option): string => $this->stripOptionLabel((string) $option),
            $options,
        ));

        return [
            'question_text' => (string) $payload['question_text'],
            'options' => $normalizedOptions,
            'answer_key' => $answerKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{question_text:string,options:mixed,answer_key:string}
     */
    private function validateTrueFalsePayload(array $payload, mixed $options, mixed $answerKey): array
    {
        if (is_string($answerKey) && preg_match('/^[A-D]$/i', trim($answerKey)) === 1 && is_array($options)) {
            $optionIndex = ord(strtoupper(trim($answerKey))) - ord('A');
            $answerKey = array_values($options)[$optionIndex] ?? $answerKey;
        }

        if (is_bool($answerKey)) {
            $normalizedAnswer = $answerKey ? 'True' : 'False';
        } elseif (is_int($answerKey) && in_array($answerKey, [0, 1], true)) {
            $normalizedAnswer = $answerKey === 1 ? 'True' : 'False';
        } elseif (is_string($answerKey)) {
            $answerText = strtolower(trim($answerKey));
            $normalizedAnswer = match (true) {
                in_array($answerText, ['true', 't', 'yes', '1'], true) => 'True',
                in_array($answerText, ['false', 'f', 'no', '0'], true) => 'False',
                preg_match('/^(?:answer\s*:\s*)?true\b/i', $answerText) === 1 => 'True',
                preg_match('/^(?:answer\s*:\s*)?false\b/i', $answerText) === 1 => 'False',
                default => null,
            };
        } else {
            $normalizedAnswer = null;
        }

        if ($normalizedAnswer === null) {
            throw new RuntimeException('True/False answer key must be True or False.');
        }

        return [
            'question_text' => (string) $payload['question_text'],
            'options' => ['True', 'False'],
            'answer_key' => $normalizedAnswer,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{question_text:string,options:mixed,answer_key:string}
     */
    private function validateOpenEndedPayload(array $payload, string $answerKey, string $questionType): array
    {
        if (blank($answerKey)) {
            throw new RuntimeException('Identification/Essay answer key must not be empty.');
        }

        if ($questionType === QuestionType::Essay->value) {
            $answerKey = $this->normalizeEssayAnswer($answerKey);
        }

        return [
            'question_text' => (string) $payload['question_text'],
            'options' => [],
            'answer_key' => $answerKey,
        ];
    }

    private function normalizeEssayAnswer(string $answerKey): string
    {
        $withoutListMarkers = preg_replace(
            '/(?:^|\R)\s*(?:\d+[\.\)]|[-*•])\s*/u',
            ' ',
            trim($answerKey),
        );

        return trim((string) preg_replace('/\s+/u', ' ', (string) $withoutListMarkers));
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
        if ($count < 2 || $count > 10) {
            throw new RuntimeException('Matching type must have between 2 and 10 pairs.');
        }
        if (blank($answerKey)) {
            throw new RuntimeException('Matching type answer key must not be empty.');
        }

        return [
            'question_text' => (string) $payload['question_text'],
            'options' => [
                'column_a' => array_values(array_map(fn (mixed $value): string => $this->stripOptionLabel((string) $value), $colA)),
                'column_b' => array_values(array_map(fn (mixed $value): string => $this->stripOptionLabel((string) $value), $colB)),
            ],
            'answer_key' => $answerKey,
        ];
    }

    private function stripOptionLabel(string $value): string
    {
        return trim((string) preg_replace('/^\s*(?:[A-Da-d]|\d+)[\.\):]\s+/u', '', $value));
    }

    /**
     * @param  array{question_text:string,options:mixed,answer_key:string}  $question
     * @param  array<int, array{question_text:string,question_type:?string,options:mixed}>  $avoidItems
     */
    private function assertQuestionQuality(array $question, string $questionType, array $avoidItems): void
    {
        $text = trim($question['question_text']);
        if (mb_strlen($text) < 15) {
            throw new RuntimeException('The question is too short or incomplete.');
        }

        if ($this->maximumCandidateSimilarity($question, $questionType, $avoidItems) >= 0.72) {
            throw new RuntimeException('The question duplicates an existing exam item.');
        }

        if ($questionType === QuestionType::TrueFalse->value
            && (str_ends_with($text, '?') || preg_match('/^(which|what|who|where|when|why|how)\b/i', $text) === 1)) {
            throw new RuntimeException('True/False must be a declarative statement, not a question.');
        }

        if ($questionType === QuestionType::MultipleChoice->value) {
            $options = $question['options'];
            if (! is_array($options) || count(array_unique(array_map('mb_strtolower', $options))) !== 4) {
                throw new RuntimeException('Multiple-choice options must be four distinct choices.');
            }
            if (collect($options)->contains(fn (string $option): bool => blank($option))) {
                throw new RuntimeException('Multiple-choice options must not be empty.');
            }
            if (collect($options)->contains(fn (string $option): bool => preg_match('/(?:\]\s*,|}\s*,|["”]\s*\]\s*,?)/u', $option) === 1)) {
                throw new RuntimeException('Multiple-choice options contain malformed serialization artifacts.');
            }
        }

        if ($questionType === QuestionType::MatchingType->value) {
            $options = $question['options'];
            $count = count($options['column_a']);
            if (count(array_unique($options['column_a'])) !== $count || count(array_unique($options['column_b'])) !== $count) {
                throw new RuntimeException('Matching columns must contain distinct entries.');
            }

            preg_match_all('/(\d+)\s*-\s*([A-Z])/i', $question['answer_key'], $matches, PREG_SET_ORDER);
            if (count($matches) !== $count) {
                throw new RuntimeException('Matching answer key must map every row exactly once.');
            }
            $rows = [];
            $letters = [];
            foreach ($matches as $match) {
                $rows[] = (int) $match[1];
                $letters[] = ord(strtoupper($match[2])) - ord('A') + 1;
            }
            sort($rows);
            sort($letters);
            if ($rows !== range(1, $count) || $letters !== range(1, $count)) {
                throw new RuntimeException('Matching answer key contains invalid or duplicate mappings.');
            }
        }
    }

    private function questionSimilarity(string $left, string $right): float
    {
        $normalize = static fn (string $text): string => trim(mb_strtolower(
            (string) preg_replace('/[^\pL\pN]+/u', ' ', $text),
        ));
        $tokens = static function (string $text): array {
            $normalized = mb_strtolower((string) preg_replace('/[^\pL\pN]+/u', ' ', $text));

            return array_values(array_unique(array_filter(
                preg_split('/\s+/u', trim($normalized)) ?: [],
                static fn (string $token): bool => mb_strlen($token) > 2,
            )));
        };

        $leftTokens = $tokens($left);
        $rightTokens = $tokens($right);
        $union = array_unique(array_merge($leftTokens, $rightTokens));
        $tokenSimilarity = $union === [] ? 0.0 : count(array_intersect($leftTokens, $rightTokens)) / count($union);

        similar_text($normalize($left), $normalize($right), $phraseSimilarityPercent);

        return max($tokenSimilarity, $phraseSimilarityPercent / 100);
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{question_text:string,question_type:?string,options:mixed}>
     */
    private function normalizeAvoidItems(array $items): array
    {
        return array_values(array_filter(array_map(static function (mixed $item): ?array {
            if (is_string($item)) {
                return ['question_text' => $item, 'question_type' => null, 'options' => null];
            }
            if (! is_array($item) || ! is_string($item['question_text'] ?? null)) {
                return null;
            }

            return [
                'question_text' => $item['question_text'],
                'question_type' => is_string($item['question_type'] ?? null) ? $item['question_type'] : null,
                'options' => $item['options'] ?? null,
            ];
        }, $items)));
    }

    /**
     * @param  array{question_text:string,options:mixed,answer_key:string}  $candidate
     * @param  array<int, array{question_text:string,question_type:?string,options:mixed}>  $items
     */
    private function maximumCandidateSimilarity(array $candidate, string $questionType, array $items): float
    {
        if ($items === []) {
            return 0.0;
        }

        $scores = [];
        foreach ($items as $item) {
            if ($questionType === QuestionType::MatchingType->value) {
                if ($item['question_type'] !== QuestionType::MatchingType->value
                    || ! is_array($candidate['options'])
                    || ! is_array($item['options'])) {
                    continue;
                }

                $candidateColumnA = $candidate['options']['column_a'] ?? [];
                $candidateColumnB = $candidate['options']['column_b'] ?? [];
                $existingColumnA = $item['options']['column_a'] ?? [];
                $existingColumnB = $item['options']['column_b'] ?? [];
                $scores[] = max(
                    $this->questionSimilarity(
                        implode(' ', array_map('strval', $candidateColumnA)),
                        implode(' ', array_map('strval', $existingColumnA)),
                    ),
                    $this->questionSimilarity(
                        implode(' ', array_map('strval', $candidateColumnB)),
                        implode(' ', array_map('strval', $existingColumnB)),
                    ),
                    $this->questionSimilarity(
                        implode(' ', array_map('strval', array_merge($candidateColumnA, $candidateColumnB))),
                        implode(' ', array_map('strval', array_merge($existingColumnA, $existingColumnB))),
                    ),
                );

                continue;
            }

            $scores[] = $this->questionSimilarity($candidate['question_text'], $item['question_text']);
        }

        return $scores === [] ? 0.0 : max($scores);
    }

    /**
     * @param  array{question_text:string,options:mixed,answer_key:string}  $candidate
     * @param  array<int, array{question_text:string,question_type:?string,options:mixed}>  $items
     */
    private function hasExactQuestionDuplicate(array $candidate, array $items): bool
    {
        $normalize = static fn (string $text): string => trim(mb_strtolower(
            (string) preg_replace('/[^\pL\pN]+/u', ' ', $text),
        ));
        $candidateText = $normalize($candidate['question_text']);

        return collect($items)->contains(
            fn (array $item): bool => $normalize($item['question_text']) === $candidateText,
        );
    }
}

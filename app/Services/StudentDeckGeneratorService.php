<?php

namespace App\Services;

use App\Enums\QuestionType;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

class StudentDeckGeneratorService
{
    /**
     * Extracts plain text from an uploaded file without requiring the Document model.
     */
    public function extractTextFromFile(UploadedFile $file): string
    {
        $mime = $file->getMimeType() ?? '';
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('Could not access uploaded file.');
        }

        return match (true) {
            $mime === 'application/pdf' => $this->extractPdf($path),
            in_array($mime, [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/msword',
            ], true) => $this->extractDocx($path),
            $mime === 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => $this->extractPptx($path),
            str_starts_with($mime, 'text/') => (string) file_get_contents($path),
            default => throw new RuntimeException(sprintf('Unsupported file type: %s', $mime)),
        };
    }

    /**
     * Splits raw text into chunks suitable for the Gemini context window.
     *
     * @return array<int, string>
     */
    public function chunkText(string $text, int $maxLength = 1200): array
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($normalized === '') {
            return [];
        }

        $chunks = [];
        $buffer = '';

        foreach (explode(' ', $normalized) as $token) {
            $candidate = $buffer === '' ? $token : $buffer.' '.$token;
            if (mb_strlen($candidate) > $maxLength) {
                $chunks[] = $buffer;
                $buffer = $token;
            } else {
                $buffer = $candidate;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    /**
     * Generates practice questions with hints from text chunks.
     *
     * Each question has:
     *   sequence, question_text, question_type, options, answer_key, hint
     *
     * @param  array<int, string>  $chunks
     * @param  array<string, int>|null  $questionTypeDistribution  type → count, must sum to $count
     * @return array<int, array{sequence:int,question_text:string,question_type:string,options:mixed,answer_key:string,hint:string}>
     */
    public function generate(array $chunks, int $count, ?array $questionTypeDistribution = null): array
    {
        if (empty($chunks)) {
            throw new RuntimeException('No text content to generate questions from.');
        }

        $contextText = implode("\n\n", array_map(
            static fn (string $chunk, int $i): string => sprintf('[Chunk %d] %s', $i + 1, $chunk),
            $chunks,
            array_keys($chunks),
        ));

        $typePlan = $this->buildTypePlan($count, $questionTypeDistribution);
        $typeSpec = collect($typePlan)
            ->countBy()
            ->map(fn (int $n, string $type): string => sprintf('%d %s', $n, $type))
            ->values()
            ->implode(', ');

        $provider = strtolower((string) config('services.ai.student_provider', 'gemini'));
        if ($provider === 'ollama') {
            return $this->generateWithOllama($chunks, $typePlan);
        }

        $prompt = implode("\n", [
            sprintf('Generate exactly %d grounded practice questions as JSON for a student study deck.', $count),
            'Source restriction: Use ONLY the provided learning materials below.',
            'NEVER ask about file names, upload details, or any metadata.',
            'Questions must test subject-matter knowledge found in the materials.',
            'Return a JSON object with key "questions".',
            'Each question must have: sequence (int, 1-indexed), question_text (string), question_type (string), options, answer_key (string), hint (string).',
            'hint must be a short clue (1-2 sentences) that helps recall WITHOUT directly revealing the answer.',
            'Question type rules:',
            '- multiple_choice: options = exactly 4 strings; answer_key = A, B, C, or D.',
            '- true_false: options = ["True","False"]; answer_key = "True" or "False".',
            '- identification: options = []; answer_key = the expected answer text.',
            '- essay: options = []; answer_key = a concise model answer or grading rubric grounded in the materials.',
            sprintf('Generate this distribution: %s.', $typeSpec),
            'Learning materials:',
            $contextText,
        ]);

        $attempts = $provider === 'ollama' ? 3 : 1;
        $lastError = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $retryPrompt = $prompt;
            if ($lastError !== null) {
                $retryPrompt .= "\nThe previous response was rejected: {$lastError}\nReturn a corrected complete questions array.";
            }

            try {
                $parsed = app(AiTextGenerator::class)->generateJson(
                    $retryPrompt,
                    0.3,
                    $this->responseSchema($count),
                    null,
                    $provider,
                );
                $rawQuestions = $parsed['questions'] ?? null;
                if (! is_array($rawQuestions)) {
                    throw new RuntimeException('AI response is missing the questions array.');
                }

                $questions = $this->validateAndNormalize($rawQuestions, $typePlan);
                if (count($questions) !== $count) {
                    throw new RuntimeException(sprintf(
                        'AI returned %d valid questions; exactly %d are required.',
                        count($questions),
                        $count,
                    ));
                }

                return $questions;
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
            }
        }

        throw new RuntimeException('AI could not generate a complete student quiz after validation: '.$lastError);
    }

    /**
     * Builds an ordered list of question types to assign per-question.
     *
     * @param  array<string, int>|null  $distribution
     * @return array<int, string>
     */
    private function buildTypePlan(int $count, ?array $distribution): array
    {
        if ($distribution !== null) {
            $total = array_sum($distribution);
            if ($total !== $count) {
                throw new RuntimeException(sprintf(
                    'Question type distribution must sum to %d (got %d).',
                    $count,
                    $total,
                ));
            }

            $plan = [];
            foreach ($distribution as $type => $n) {
                for ($i = 0; $i < $n; $i++) {
                    $plan[] = $type;
                }
            }

            return $plan;
        }

        // Default: roughly 70 % multiple_choice, 30 % true_false
        $mc = (int) round($count * 0.7);
        $tf = $count - $mc;
        $plan = array_fill(0, $mc, QuestionType::MultipleChoice->value);

        return array_merge($plan, array_fill(0, $tf, QuestionType::TrueFalse->value));
    }

    /**
     * @param  array<int, string>  $chunks
     * @param  array<int, string>  $typePlan
     * @return array<int, array{sequence:int,question_text:string,question_type:string,options:mixed,answer_key:string,hint:string}>
     */
    private function generateWithOllama(array $chunks, array $typePlan): array
    {
        $questions = [];

        foreach ($typePlan as $index => $questionType) {
            $context = $this->contextWindow($chunks, $index);
            $avoid = array_column($questions, 'question_text');
            $basePrompt = implode("\n", [
                'Generate exactly one grounded practice question as JSON.',
                'Use only the supplied learning-material context.',
                sprintf('Required question type: %s.', $questionType),
                'Return keys: question_text, options, answer_key, hint.',
                'Do not include question_type or sequence.',
                'Do not repeat or closely paraphrase an avoided question.',
                'The hint must help recall without directly giving the answer.',
                '- multiple_choice: exactly four plain options without A/B/C/D labels; answer_key is A, B, C, or D.',
                '- true_false: write a declarative statement; options are True and False; answer_key is True or False.',
                '- identification: options is empty; answer_key is the expected term.',
                '- essay: options is empty; answer_key is a concise rubric or model answer.',
                $avoid === [] ? 'Avoided questions: none.' : "Avoided questions:\n- ".implode("\n- ", $avoid),
                'Learning-material context:',
                $context,
            ]);

            $lastError = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $prompt = $basePrompt;
                if ($lastError !== null) {
                    $prompt .= "\nPrevious response rejected: {$lastError}\nReturn a corrected, different question.";
                }

                try {
                    $payload = app(AiTextGenerator::class)->generateJson(
                        $prompt,
                        0.3,
                        $this->singleQuestionSchema($questionType),
                        null,
                        'ollama',
                    );
                    $questionText = $payload['question_text'] ?? null;
                    $answerKey = $payload['answer_key'] ?? null;
                    $options = $payload['options'] ?? [];
                    if (! is_string($questionText) || blank($questionText) || ! is_string($answerKey) || ! is_array($options)) {
                        throw new RuntimeException('Question fields are incomplete.');
                    }

                    $normalized = $this->normalizeForType($questionType, $options, $answerKey);
                    if ($normalized === null) {
                        throw new RuntimeException('Question options or answer key do not match the required type.');
                    }
                    if ($this->isDuplicateQuestion($questionText, $avoid)) {
                        throw new RuntimeException('Question duplicates an earlier item.');
                    }

                    $questions[] = [
                        'sequence' => $index + 1,
                        'question_text' => trim($questionText),
                        'question_type' => $normalized['type'],
                        'options' => $normalized['options'],
                        'answer_key' => $normalized['answer_key'],
                        'hint' => is_string($payload['hint'] ?? null) ? trim($payload['hint']) : '',
                    ];
                    $lastError = null;
                    break;
                } catch (RuntimeException $exception) {
                    $lastError = $exception->getMessage();
                }
            }

            if ($lastError !== null) {
                throw new RuntimeException(sprintf(
                    'AI could not generate student question %d after validation: %s',
                    $index + 1,
                    $lastError,
                ));
            }
        }

        return $questions;
    }

    /** @param array<int, string> $chunks */
    private function contextWindow(array $chunks, int $questionIndex): string
    {
        $windowSize = min(6, count($chunks));
        $start = ($questionIndex * $windowSize) % count($chunks);
        $selected = [];
        for ($offset = 0; $offset < $windowSize; $offset++) {
            $selected[] = $chunks[($start + $offset) % count($chunks)];
        }

        return mb_substr(implode("\n\n", $selected), 0, 10000);
    }

    /** @return array<string, mixed> */
    private function singleQuestionSchema(string $questionType): array
    {
        $options = match ($questionType) {
            QuestionType::MultipleChoice->value => ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'minItems' => 4, 'maxItems' => 4],
            QuestionType::TrueFalse->value => ['type' => 'ARRAY', 'items' => ['type' => 'STRING', 'enum' => ['True', 'False']], 'minItems' => 2, 'maxItems' => 2],
            default => ['type' => 'ARRAY', 'maxItems' => 0],
        };
        $answerKey = match ($questionType) {
            QuestionType::MultipleChoice->value => ['type' => 'STRING', 'enum' => ['A', 'B', 'C', 'D']],
            QuestionType::TrueFalse->value => ['type' => 'STRING', 'enum' => ['True', 'False']],
            default => ['type' => 'STRING'],
        };

        return [
            'type' => 'OBJECT',
            'properties' => [
                'question_text' => ['type' => 'STRING'],
                'options' => $options,
                'answer_key' => $answerKey,
                'hint' => ['type' => 'STRING'],
            ],
            'required' => ['question_text', 'options', 'answer_key', 'hint'],
        ];
    }

    /** @param array<int, string> $existing */
    private function isDuplicateQuestion(string $question, array $existing): bool
    {
        $normalize = static fn (string $text): string => mb_strtolower(trim((string) preg_replace('/[^\pL\pN]+/u', ' ', $text)));
        $candidate = $normalize($question);

        return collect($existing)->contains(fn (string $text): bool => $normalize($text) === $candidate);
    }

    /**
     * Validates and normalises the raw questions array from Gemini.
     *
     * @param  array<int, mixed>  $rawQuestions
     * @param  array<int, string>  $typePlan
     * @return array<int, array{sequence:int,question_text:string,question_type:string,options:mixed,answer_key:string,hint:string}>
     */
    private function validateAndNormalize(array $rawQuestions, array $typePlan): array
    {
        $validated = [];
        $sequence = 1;

        foreach ($rawQuestions as $item) {
            if (! is_array($item)) {
                continue;
            }

            $questionText = $item['question_text'] ?? null;
            $questionType = $typePlan[$sequence - 1] ?? QuestionType::MultipleChoice->value;
            $options = $item['options'] ?? [];
            $answerKey = $item['answer_key'] ?? null;
            $hint = $item['hint'] ?? '';

            if (! is_string($questionText) || blank($questionText)) {
                continue;
            }
            if (! is_string($answerKey)) {
                continue;
            }

            $options = is_array($options) ? $options : [];
            $normalized = $this->normalizeForType((string) $questionType, $options, (string) $answerKey);
            if ($normalized === null) {
                continue;
            }

            $validated[] = [
                'sequence' => $sequence++,
                'question_text' => trim($questionText),
                'question_type' => $normalized['type'],
                'options' => $normalized['options'],
                'answer_key' => $normalized['answer_key'],
                'hint' => is_string($hint) ? trim($hint) : '',
            ];
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function responseSchema(int $count): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'questions' => [
                    'type' => 'ARRAY',
                    'minItems' => $count,
                    'maxItems' => $count,
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'sequence' => ['type' => 'INTEGER'],
                            'question_text' => ['type' => 'STRING'],
                            'question_type' => ['type' => 'STRING', 'enum' => [
                                QuestionType::MultipleChoice->value,
                                QuestionType::TrueFalse->value,
                                QuestionType::Identification->value,
                                QuestionType::Essay->value,
                            ]],
                            'options' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                            'answer_key' => ['type' => 'STRING'],
                            'hint' => ['type' => 'STRING'],
                        ],
                        'required' => ['sequence', 'question_text', 'question_type', 'options', 'answer_key', 'hint'],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array{type:string,options:mixed,answer_key:string}|null
     */
    private function normalizeForType(string $type, array $options, string $answerKey): ?array
    {
        return match ($type) {
            QuestionType::MultipleChoice->value => $this->normalizeMultipleChoice($options, $answerKey),
            QuestionType::TrueFalse->value => $this->normalizeTrueFalse($options, $answerKey),
            QuestionType::Identification->value => strlen(trim($answerKey)) > 0
                ? ['type' => $type, 'options' => [], 'answer_key' => trim($answerKey)]
                : null,
            QuestionType::Essay->value => strlen(trim($answerKey)) > 0
                ? ['type' => $type, 'options' => [], 'answer_key' => trim($answerKey)]
                : null,
            default => null,
        };
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array{type:string,options:array<int,string>,answer_key:string}|null
     */
    private function normalizeMultipleChoice(array $options, string $answerKey): ?array
    {
        if (count($options) !== 4) {
            return null;
        }
        $letter = strtoupper(substr(trim($answerKey), 0, 1));
        if (! in_array($letter, ['A', 'B', 'C', 'D'], true)) {
            return null;
        }

        return [
            'type' => QuestionType::MultipleChoice->value,
            'options' => array_values(array_map('strval', $options)),
            'answer_key' => $letter,
        ];
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array{type:string,options:array<int,string>,answer_key:string}|null
     */
    private function normalizeTrueFalse(array $options, string $answerKey): ?array
    {
        $lower = strtolower(trim($answerKey));
        if (! in_array($lower, ['true', 'false'], true)) {
            return null;
        }

        return [
            'type' => QuestionType::TrueFalse->value,
            'options' => ['True', 'False'],
            'answer_key' => ucfirst($lower),
        ];
    }

    private function extractPdf(string $absolutePath): string
    {
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = sprintf(
            'pdftotext -layout -q %s - 2>%s',
            escapeshellarg($absolutePath),
            $nullDevice,
        );
        $output = shell_exec($command);

        if (is_string($output) && trim($output) !== '') {
            return trim((string) preg_replace('/\s+/', ' ', $output));
        }

        // Heuristic fallback for simple PDFs when pdftotext is unavailable
        $binary = (string) file_get_contents($absolutePath);
        preg_match_all('/\((.*?)\)\s*Tj/s', $binary, $single);
        preg_match_all('/\[(.*?)\]\s*TJ/s', $binary, $array);

        $parts = $single[1] ?? [];
        foreach ($array[1] ?? [] as $chunk) {
            preg_match_all('/\((.*?)\)/s', $chunk, $inner);
            $parts = array_merge($parts, $inner[1] ?? []);
        }

        return trim((string) preg_replace('/\s+/', ' ', implode(' ', array_map('stripcslashes', $parts))));
    }

    private function extractDocx(string $absolutePath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Could not open DOCX file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            throw new RuntimeException('DOCX contains no readable content.');
        }

        $xml = str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $xml);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($xml)));
    }

    private function extractPptx(string $absolutePath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Could not open PPTX file.');
        }

        $slideNames = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (is_string($name) && preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) {
                $slideNames[] = $name;
            }
        }

        sort($slideNames);
        $parts = [];
        foreach ($slideNames as $name) {
            $xml = $zip->getFromName($name);
            if (! is_string($xml) || $xml === '') {
                continue;
            }
            $xml = str_replace(['</a:p>', '</a:br>', '</a:t>'], ["\n", "\n", ' '], $xml);
            $parts[] = strip_tags($xml);
        }

        $zip->close();

        return trim((string) preg_replace('/\s+/', ' ', implode(' ', $parts)));
    }
}

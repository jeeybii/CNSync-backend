<?php

namespace App\Services;

use App\Enums\QuestionType;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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

        $apiKey = (string) config('services.gemini.api_key');
        if (blank($apiKey)) {
            throw new RuntimeException('Gemini API key is missing. Set GEMINI_API_KEY in .env.');
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
            sprintf('Generate this distribution: %s.', $typeSpec),
            'Learning materials:',
            $contextText,
        ]);

        $baseUrl = rtrim((string) config('services.gemini.base_url'), '/');
        $response = $this->callGeminiWithFallback($baseUrl, $apiKey, $prompt);
        $parsed = $this->parseJsonPayload($response);

        $rawQuestions = $parsed['questions'] ?? null;
        if (! is_array($rawQuestions)) {
            throw new RuntimeException('Gemini returned no questions array.');
        }

        return $this->validateAndNormalize($rawQuestions, $typePlan);
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
            $questionType = $item['question_type'] ?? ($typePlan[$sequence - 1] ?? QuestionType::MultipleChoice->value);
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

    private function callGeminiWithFallback(string $baseUrl, string $apiKey, string $prompt): Response
    {
        $models = array_values(array_unique(array_filter([
            (string) config('services.gemini.model'),
            (string) config('services.gemini.fallback_model'),
        ])));

        $retries = max(1, (int) config('services.gemini.retries', 3));
        $sleepMs = max(200, (int) config('services.gemini.retry_sleep_ms', 1000));
        $lastResponse = null;

        foreach ($models as $model) {
            $url = sprintf('%s/v1beta/models/%s:generateContent', $baseUrl, $model);

            for ($attempt = 1; $attempt <= $retries; $attempt++) {
                $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                    ->timeout((int) config('services.gemini.timeout', 120))
                    ->connectTimeout(15)
                    ->post($url, [
                        'contents' => [[
                            'parts' => [['text' => $prompt]],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.3,
                            'responseMimeType' => 'application/json',
                        ],
                    ]);

                if ($response->successful()) {
                    return $response;
                }

                $lastResponse = $response;
                if (! in_array($response->status(), [429, 500, 502, 503, 504], true)) {
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
            throw new RuntimeException('Gemini returned an empty response.');
        }

        /** @var mixed $parsed */
        $parsed = json_decode($jsonText, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('Gemini response is not valid JSON.');
        }

        return $parsed;
    }
}

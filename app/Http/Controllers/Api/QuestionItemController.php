<?php

namespace App\Http\Controllers\Api;

use App\Enums\BloomLevel;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\QuestionItem;
use App\Models\QuestionRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionItemController extends Controller
{
    public function store(Request $request, Project $project, QuestionRun $questionRun): JsonResponse
    {
        $base = $request->validate([
            'topic_name' => ['required', 'string', 'max:255'],
            'bloom_level' => ['required', 'string', Rule::in(BloomLevel::values())],
            'question_type' => ['required', 'string', Rule::in(QuestionType::values())],
            'question_text' => ['required', 'string'],
            'options' => ['present'],
            'answer_key' => ['required', 'string', 'max:1000'],
            'citations' => ['nullable', 'array'],
        ]);

        $payload = $this->applyTypeRules($base);

        $item = DB::transaction(function () use ($questionRun, $payload): QuestionItem {
            $nextSeq = (int) $questionRun->items()->max('sequence') + 1;
            $created = $questionRun->items()->create(array_merge($payload, [
                'sequence' => max(1, $nextSeq),
            ]));
            $questionRun->update(['generated_items' => $questionRun->items()->count()]);

            return $created;
        });

        return response()->json(['data' => $item->fresh()], 201);
    }

    public function update(Request $request, Project $project, QuestionRun $questionRun, QuestionItem $questionItem): JsonResponse
    {
        $validated = $request->validate([
            'topic_name' => ['sometimes', 'string', 'max:255'],
            'bloom_level' => ['sometimes', 'string', Rule::in(BloomLevel::values())],
            'question_type' => ['sometimes', 'string', Rule::in(QuestionType::values())],
            'question_text' => ['sometimes', 'string'],
            'options' => ['sometimes', 'present'],
            'answer_key' => ['sometimes', 'string', 'max:1000'],
            'citations' => ['sometimes', 'nullable', 'array'],
        ]);

        $merged = [
            'topic_name' => $validated['topic_name'] ?? $questionItem->topic_name,
            'bloom_level' => $validated['bloom_level'] ?? $questionItem->bloom_level->value,
            'question_type' => $validated['question_type'] ?? $questionItem->question_type,
            'question_text' => $validated['question_text'] ?? $questionItem->question_text,
            'options' => $validated['options'] ?? $questionItem->options,
            'answer_key' => $validated['answer_key'] ?? $questionItem->answer_key,
            'citations' => array_key_exists('citations', $validated)
                ? $validated['citations']
                : $questionItem->citations,
        ];

        $payload = $this->applyTypeRules($merged);
        $questionItem->update($payload);

        return response()->json(['data' => $questionItem->fresh()]);
    }

    public function destroy(Project $project, QuestionRun $questionRun, QuestionItem $questionItem): JsonResponse
    {
        DB::transaction(function () use ($questionRun, $questionItem): void {
            $questionItem->delete();
            $this->renumberSequences($questionRun);
            $questionRun->update(['generated_items' => $questionRun->items()->count()]);
        });

        return response()->json(null, 204);
    }

    private function renumberSequences(QuestionRun $questionRun): void
    {
        $items = $questionRun->items()->orderBy('sequence')->orderBy('id')->get();
        foreach ($items as $index => $item) {
            $newSeq = $index + 1;
            if ((int) $item->sequence !== $newSeq) {
                $item->update(['sequence' => $newSeq]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyTypeRules(array $data): array
    {
        $type = (string) $data['question_type'];
        $options = $data['options'];
        $answer = trim((string) $data['answer_key']);

        return match ($type) {
            QuestionType::MultipleChoice->value => $this->validateMultipleChoice($data, $options, $answer),
            QuestionType::TrueFalse->value => $this->validateTrueFalse($data, $options, $answer),
            QuestionType::Identification->value => $this->validateIdentification($data, $options, $answer),
            QuestionType::Essay->value => $this->validateEssay($data, $options, $answer),
            QuestionType::MatchingType->value => $this->validateMatchingType($data, $options, $answer),
            default => throw ValidationException::withMessages(['question_type' => ['Unsupported question type.']]),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateMultipleChoice(array $data, mixed $options, string $answer): array
    {
        if (! is_array($options) || count($options) !== 4) {
            throw ValidationException::withMessages(['options' => ['Multiple choice requires exactly 4 options.']]);
        }
        foreach ($options as $i => $opt) {
            if (! is_string($opt) || trim($opt) === '') {
                throw ValidationException::withMessages(["options.{$i}" => ['Each option must be a non-empty string.']]);
            }
        }
        $letter = strtoupper(substr($answer, 0, 1));
        if (! in_array($letter, ['A', 'B', 'C', 'D'], true)) {
            throw ValidationException::withMessages(['answer_key' => ['Answer must be A, B, C, or D.']]);
        }

        $data['options'] = array_values(array_map('strval', $options));
        $data['answer_key'] = $letter;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateTrueFalse(array $data, mixed $options, string $answer): array
    {
        if (! is_array($options) || count($options) !== 2) {
            throw ValidationException::withMessages(['options' => ['True/false requires exactly 2 options.']]);
        }
        $norm = array_map(fn ($o) => strtolower(trim((string) $o)), $options);
        sort($norm);
        if ($norm !== ['false', 'true']) {
            throw ValidationException::withMessages(['options' => ['True/false options must be True and False.']]);
        }
        $lower = strtolower($answer);
        if (! in_array($lower, ['true', 'false'], true)) {
            throw ValidationException::withMessages(['answer_key' => ['Answer must be True or False.']]);
        }

        $data['options'] = ['True', 'False'];
        $data['answer_key'] = ucfirst($lower);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateIdentification(array $data, mixed $options, string $answer): array
    {
        if (! is_array($options) || ! empty($options)) {
            throw ValidationException::withMessages(['options' => ['Identification questions must have an empty options array.']]);
        }
        if ($answer === '') {
            throw ValidationException::withMessages(['answer_key' => ['Identification answer key must not be empty.']]);
        }

        $data['options'] = [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateEssay(array $data, mixed $options, string $answer): array
    {
        if (! is_array($options) || ! empty($options)) {
            throw ValidationException::withMessages(['options' => ['Essay questions must have an empty options array.']]);
        }
        if ($answer === '') {
            throw ValidationException::withMessages(['answer_key' => ['Essay answer key (model answer / rubric) must not be empty.']]);
        }

        $data['options'] = [];

        return $data;
    }

    /**
     * Matching-type options shape:
     * { "column_a": ["Premise 1", "Premise 2", ...], "column_b": ["Response A", "Response B", ...] }
     * answer_key: "1-A,2-C,3-B" — 1-indexed column_a position matched to column_b letter (A=1st, B=2nd, …)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateMatchingType(array $data, mixed $options, string $answer): array
    {
        if (! is_array($options) || ! isset($options['column_a'], $options['column_b'])) {
            throw ValidationException::withMessages(['options' => ['Matching type requires options with column_a and column_b arrays.']]);
        }

        $colA = $options['column_a'];
        $colB = $options['column_b'];

        if (! is_array($colA) || ! is_array($colB)) {
            throw ValidationException::withMessages(['options' => ['column_a and column_b must be arrays.']]);
        }

        $count = count($colA);
        if ($count < 3 || $count > 10 || count($colB) !== $count) {
            throw ValidationException::withMessages(['options' => ['column_a and column_b must have equal length between 3 and 10.']]);
        }

        foreach ($colA as $i => $item) {
            if (! is_string($item) || trim($item) === '') {
                throw ValidationException::withMessages(["options.column_a.{$i}" => ['Each column_a item must be a non-empty string.']]);
            }
        }
        foreach ($colB as $i => $item) {
            if (! is_string($item) || trim($item) === '') {
                throw ValidationException::withMessages(["options.column_b.{$i}" => ['Each column_b item must be a non-empty string.']]);
            }
        }

        $this->validateMatchingAnswerKey($answer, $count);

        $data['options'] = [
            'column_a' => array_values(array_map('strval', $colA)),
            'column_b' => array_values(array_map('strval', $colB)),
        ];

        return $data;
    }

    private function validateMatchingAnswerKey(string $answer, int $pairCount): void
    {
        if ($answer === '') {
            throw ValidationException::withMessages(['answer_key' => ['Matching type answer key must not be empty.']]);
        }

        $pairs = array_map('trim', explode(',', $answer));
        if (count($pairs) !== $pairCount) {
            throw ValidationException::withMessages(['answer_key' => [sprintf('Answer key must contain exactly %d pairs.', $pairCount)]]);
        }

        $validLetters = array_map(
            static fn (int $n): string => chr(ord('A') + $n - 1),
            range(1, $pairCount),
        );

        foreach ($pairs as $pair) {
            if (! preg_match('/^(\d+)-([A-Z])$/i', $pair, $matches)) {
                throw ValidationException::withMessages(['answer_key' => ['Each pair must be in the format "N-L" (e.g. 1-A).']]);
            }
            $num = (int) $matches[1];
            $letter = strtoupper($matches[2]);
            if ($num < 1 || $num > $pairCount) {
                throw ValidationException::withMessages(['answer_key' => [sprintf('Pair number %d is out of range (1–%d).', $num, $pairCount)]]);
            }
            if (! in_array($letter, $validLetters, true)) {
                throw ValidationException::withMessages(['answer_key' => [sprintf('Pair letter %s is out of range (A–%s).', $letter, end($validLetters))]]);
            }
        }
    }
}

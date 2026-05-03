<?php

namespace App\Http\Controllers\Api;

use App\Enums\BloomLevel;
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
            'question_type' => ['required', 'string', Rule::in(['multiple_choice', 'true_false'])],
            'question_text' => ['required', 'string'],
            'options' => ['required', 'array'],
            'answer_key' => ['required', 'string', 'max:255'],
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
            'question_type' => ['sometimes', 'string', Rule::in(['multiple_choice', 'true_false'])],
            'question_text' => ['sometimes', 'string'],
            'options' => ['sometimes', 'array'],
            'answer_key' => ['sometimes', 'string', 'max:255'],
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
        $type = $data['question_type'];
        $options = $data['options'];
        $answer = trim((string) $data['answer_key']);

        if (! is_array($options)) {
            throw ValidationException::withMessages(['options' => ['Options must be an array.']]);
        }

        if ($type === 'multiple_choice') {
            if (count($options) !== 4) {
                throw ValidationException::withMessages(['options' => ['Multiple choice requires exactly 4 options.']]);
            }
            foreach ($options as $i => $opt) {
                if (! is_string($opt) || trim($opt) === '') {
                    throw ValidationException::withMessages(["options.$i" => ['Each option must be a non-empty string.']]);
                }
            }
            $trimmed = trim($answer);
            $letter = strtoupper(substr($trimmed, 0, 1));
            if (! in_array($letter, ['A', 'B', 'C', 'D'], true)) {
                throw ValidationException::withMessages(['answer_key' => ['Answer must be A, B, C, or D.']]);
            }

            $data['answer_key'] = $letter;
        } else {
            if (count($options) !== 2) {
                throw ValidationException::withMessages(['options' => ['True/false requires exactly 2 options.']]);
            }
            $norm = array_map(fn ($o) => strtolower(trim((string) $o)), $options);
            sort($norm);
            if ($norm !== ['false', 'true']) {
                throw ValidationException::withMessages(['options' => ['True/false options must be True and False.']]);
            }

            $data['options'] = ['True', 'False'];
            $at = strtolower($answer);
            if (! in_array($at, ['true', 'false'], true)) {
                throw ValidationException::withMessages(['answer_key' => ['Answer must be True or False.']]);
            }
            $data['answer_key'] = ucfirst($at);
        }

        return $data;
    }
}

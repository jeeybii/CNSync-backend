<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Project;
use App\Models\QuestionItem;
use App\Models\QuestionRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Assessment::query()
            ->where('user_id', $request->user()->id)
            ->with(['questionRun.items' => fn ($q) => $q->orderBy('sequence')])
            ->latest()
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Assessment $a): array => $this->serializeAssessment($a)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'question_run_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'exam_type' => ['required', 'string', 'max:64'],
            'number_of_items' => ['required', 'integer', 'min:0'],
            'tos' => ['required', 'array'],
        ]);

        $user = $request->user();
        $project = Project::query()
            ->where('user_id', $user->id)
            ->whereKey($validated['project_id'])
            ->firstOrFail();

        QuestionRun::query()
            ->where('project_id', $project->id)
            ->whereKey($validated['question_run_id'])
            ->firstOrFail();

        $assessment = Assessment::query()->create([
            'user_id' => $user->id,
            'project_id' => $validated['project_id'],
            'question_run_id' => $validated['question_run_id'],
            'title' => $validated['title'],
            'exam_type' => $validated['exam_type'],
            'number_of_items' => $validated['number_of_items'],
            'tos' => $validated['tos'],
        ]);

        $assessment->load(['questionRun.items' => fn ($q) => $q->orderBy('sequence')]);

        return response()->json([
            'data' => $this->serializeAssessment($assessment),
        ], 201);
    }

    public function show(Assessment $assessment): JsonResponse
    {
        $assessment->load(['questionRun.items' => fn ($q) => $q->orderBy('sequence')]);

        return response()->json([
            'data' => $this->serializeAssessment($assessment),
        ]);
    }

    public function update(Request $request, Assessment $assessment): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'exam_type' => ['sometimes', 'string', 'max:64'],
            'number_of_items' => ['sometimes', 'integer', 'min:0'],
            'tos' => ['sometimes', 'array'],
        ]);

        $assessment->update($validated);
        $assessment->load(['questionRun.items' => fn ($q) => $q->orderBy('sequence')]);

        return response()->json([
            'data' => $this->serializeAssessment($assessment),
        ]);
    }

    public function destroy(Assessment $assessment): JsonResponse
    {
        $assessment->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAssessment(Assessment $assessment): array
    {
        $run = $assessment->questionRun;
        $items = $run?->items ?? collect();

        return [
            'id' => $assessment->id,
            'created_at' => $assessment->created_at?->toIso8601String(),
            'updated_at' => $assessment->updated_at?->toIso8601String(),
            'title' => $assessment->title,
            'exam_type' => $assessment->exam_type,
            'number_of_items' => $assessment->number_of_items,
            'project_id' => $assessment->project_id,
            'question_run_id' => $assessment->question_run_id,
            'tos' => $assessment->tos,
            'question_items' => $items->map(fn (QuestionItem $item): array => $this->serializeQuestionItem($item))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeQuestionItem(QuestionItem $item): array
    {
        return [
            'id' => $item->id,
            'topic_name' => $item->topic_name,
            'bloom_level' => $item->bloom_level->value,
            'question_type' => $item->question_type,
            'sequence' => $item->sequence,
            'question_text' => $item->question_text,
            'options' => $item->options,
            'answer_key' => $item->answer_key,
            'citations' => $item->citations,
        ];
    }
}

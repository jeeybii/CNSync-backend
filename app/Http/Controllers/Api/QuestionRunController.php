<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Models\QuestionRun;
use App\Models\TosRun;
use App\Services\GeminiQuestionGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class QuestionRunController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $runs = $project->questionRuns()
            ->with('items')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $runs]);
    }

    public function store(Request $request, Project $project, GeminiQuestionGenerator $generator): JsonResponse
    {
        $validated = $request->validate([
            'tos_run_id' => ['nullable', 'integer'],
        ]);

        $tosRun = $this->resolveTosRun($project, $validated['tos_run_id'] ?? null);
        $documents = Document::query()
            ->where('project_id', $project->id)
            ->where('status', DocumentStatus::Analyzed)
            ->with('extractedChunks')
            ->get();

        if ($documents->isEmpty()) {
            return response()->json([
                'message' => 'No analyzed documents are available for grounded question generation.',
            ], 422);
        }

        $requestedItems = (int) $tosRun->allocations->sum('item_count');
        $questionRun = $project->questionRuns()->create([
            'tos_run_id' => $tosRun->id,
            'requested_items' => $requestedItems,
            'status' => 'processing',
        ]);

        $sequence = 1;
        $createdItems = [];

        try {
            foreach ($tosRun->allocations as $allocation) {
                for ($i = 0; $i < $allocation->item_count; $i++) {
                    $contexts = $generator->retrieveContext($allocation->topic_name, $documents->all());
                    $generated = $generator->generate($allocation, $contexts);

                    $createdItems[] = [
                        'topic_name' => $allocation->topic_name,
                        'bloom_level' => $allocation->bloom_level->value,
                        'sequence' => $sequence++,
                        'question_text' => $generated['question_text'],
                        'options' => $generated['options'],
                        'answer_key' => $generated['answer_key'],
                        'citations' => $generated['citations'],
                    ];
                }
            }

            DB::transaction(function () use ($questionRun, $createdItems): void {
                $questionRun->items()->createMany($createdItems);
                $questionRun->update([
                    'generated_items' => count($createdItems),
                    'status' => 'completed',
                    'failed_reason' => null,
                ]);
            });
        } catch (\Throwable $exception) {
            $questionRun->update([
                'status' => 'failed',
                'failed_reason' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return response()->json([
            'data' => $questionRun->fresh(['items', 'tosRun']),
        ], 201);
    }

    public function show(Project $project, QuestionRun $questionRun): JsonResponse
    {
        return response()->json([
            'data' => $questionRun->load(['items', 'tosRun']),
        ]);
    }

    private function resolveTosRun(Project $project, ?int $tosRunId): TosRun
    {
        if ($tosRunId !== null) {
            return TosRun::query()
                ->where('project_id', $project->id)
                ->whereKey($tosRunId)
                ->with('allocations')
                ->firstOrFail();
        }

        return TosRun::query()
            ->where('project_id', $project->id)
            ->with('allocations')
            ->latest('id')
            ->firstOrFail();
    }
}

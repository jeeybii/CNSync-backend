<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Project;
use App\Models\QuestionRun;
use App\Models\TosRun;
use App\Services\GeminiQuestionGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        // Question generation can take long when model capacity is constrained.
        // Allow this request to run without PHP execution timeout.
        set_time_limit(0);

        $validated = $request->validate([
            'tos_run_id' => ['nullable', 'integer'],
            'question_type_distribution' => ['sometimes', 'array'],
            'question_type_distribution.multiple_choice' => ['required_with:question_type_distribution', 'integer', 'min:0'],
            'question_type_distribution.true_false' => ['required_with:question_type_distribution', 'integer', 'min:0'],
        ]);

        $tosRun = $this->resolveTosRun($project, $validated['tos_run_id'] ?? null);
        $requestedItems = (int) $tosRun->allocations->sum('item_count');
        $questionTypePlan = $this->resolveQuestionTypePlan($requestedItems, $validated['question_type_distribution'] ?? null);
        $documents = Document::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [DocumentStatus::Analyzed, DocumentStatus::TosReady])
            ->with('extractedChunks')
            ->get();

        if ($documents->isEmpty() || ! $this->hasRequiredDocumentKinds($documents)) {
            return response()->json([
                'message' => 'Question generation requires one analyzed syllabus and at least one analyzed learning material.',
            ], 422);
        }

        $questionRun = $project->questionRuns()->create([
            'tos_run_id' => $tosRun->id,
            'requested_items' => $requestedItems,
            'status' => 'processing',
        ]);

        $sequence = 1;
        $createdItems = [];

        try {
            $requests = [];
            $contextsByRequestId = [];
            $questionTypesByRequestId = [];
            $questionTypeIndex = 0;

            foreach ($tosRun->allocations as $allocation) {
                $contexts = $generator->retrieveContext($allocation->topic_name, $documents->all());
                for ($i = 0; $i < $allocation->item_count; $i++) {
                    $requestId = sprintf(
                        'alloc-%d-item-%d',
                        $allocation->id,
                        $i + 1,
                    );

                    $requests[] = [
                        'id' => $requestId,
                        'topic_name' => $allocation->topic_name,
                        'bloom_level' => $allocation->bloom_level->value,
                        'question_type' => $questionTypePlan[$questionTypeIndex],
                    ];
                    $contextsByRequestId[$requestId] = $contexts;
                    $questionTypesByRequestId[$requestId] = $questionTypePlan[$questionTypeIndex];
                    $questionTypeIndex++;
                }
            }

            $generatedByRequest = [];
            try {
                $generatedByRequest = $generator->generateBatch($requests, $contextsByRequestId);
            } catch (\Throwable) {
                // Batch generation is a cost/latency optimization.
                // If the model returns non-batch schema, fallback to per-item generation.
                $generatedByRequest = [];
            }

            foreach ($requests as $requestSpec) {
                $requestId = $requestSpec['id'];
                $generated = $generatedByRequest[$requestId] ?? null;

                // Regenerate only missing/invalid rows to keep API costs lower than all-single mode.
                if (! is_array($generated)) {
                    $generated = $generator->generateOne(
                        $requestSpec['topic_name'],
                        $requestSpec['bloom_level'],
                        $questionTypesByRequestId[$requestId],
                        $contextsByRequestId[$requestId],
                    );
                }

                $createdItems[] = [
                    'topic_name' => $requestSpec['topic_name'],
                    'bloom_level' => $requestSpec['bloom_level'],
                    'question_type' => $questionTypesByRequestId[$requestId],
                    'sequence' => $sequence++,
                    'question_text' => $generated['question_text'],
                    'options' => $generated['options'],
                    'answer_key' => $generated['answer_key'],
                    'citations' => $generated['citations'] ?? collect($contextsByRequestId[$requestId])
                        ->map(fn (array $chunk): array => [
                            'document_id' => $chunk['document_id'],
                            'page_number' => $chunk['page_number'],
                        ])
                        ->unique()
                        ->values()
                        ->all(),
                ];
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

    /**
     * @param  array<string, int>|null  $distribution
     * @return array<int, string>
     */
    private function resolveQuestionTypePlan(int $requestedItems, ?array $distribution): array
    {
        if ($distribution === null) {
            return array_fill(0, $requestedItems, 'multiple_choice');
        }

        $multipleChoice = (int) ($distribution['multiple_choice'] ?? 0);
        $trueFalse = (int) ($distribution['true_false'] ?? 0);

        if (($multipleChoice + $trueFalse) !== $requestedItems) {
            throw ValidationException::withMessages([
                'question_type_distribution' => [
                    sprintf('Question type counts must sum to %d.', $requestedItems),
                ],
            ]);
        }

        return array_merge(
            array_fill(0, $multipleChoice, 'multiple_choice'),
            array_fill(0, $trueFalse, 'true_false'),
        );
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function hasRequiredDocumentKinds(Collection $documents): bool
    {
        $hasSyllabus = $documents->contains(fn (Document $document): bool => $document->kind === DocumentKind::Syllabus);
        $hasMaterial = $documents->contains(fn (Document $document): bool => $document->kind === DocumentKind::Material);

        return $hasSyllabus && $hasMaterial;
    }
}

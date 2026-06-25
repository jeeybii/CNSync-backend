<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Document;
use App\Models\Project;
use App\Models\QuestionRun;
use App\Models\SyllabusTopic;
use App\Models\TosRun;
use App\Services\GeminiQuestionGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
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
            'question_type_distribution.multiple_choice' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.true_false' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.identification' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.essay' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.matching_type' => ['sometimes', 'integer', 'min:0'],
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

        // Only pull context from learning materials — the syllabus is used for TOS structure only,
        // not as a question source (prevents "What is the course title?" type questions).
        $materialDocuments = $documents
            ->filter(fn (Document $document): bool => $document->kind === DocumentKind::Material)
            ->values()
            ->all();

        // Pre-load syllabus topics so we can supplement context for any topic not
        // covered in the learning materials (used as a subject-scope guide, not a source).
        $syllabusTopics = SyllabusTopic::query()
            ->where('project_id', $project->id)
            ->get(['topic_name', 'hours', 'objective'])
            ->keyBy('topic_name');

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
                $contexts = $generator->retrieveContext($allocation->topic_name, $materialDocuments);

                // When no learning material explicitly mentions this topic, supplement with the
                // syllabus topic objective as a subject-scope guide so the AI knows what the
                // topic is about — without quoting from the syllabus directly.
                if (! $generator->hasTopicMatch($allocation->topic_name, $materialDocuments)) {
                    $syllabusTopic = $syllabusTopics->get($allocation->topic_name);
                    if ($syllabusTopic) {
                        $guideText = sprintf(
                            '[TOPIC GUIDE - use only to understand the subject scope, do NOT quote from this] Topic: %s | Scope: %s',
                            $syllabusTopic->topic_name,
                            $syllabusTopic->objective ?? 'No detailed scope provided — generate a standard educational question for this topic area.',
                        );
                        // Prepend the guide so the AI can orient the question to the right topic,
                        // then fallback material content follows for actual substance.
                        array_unshift($contexts, [
                            'document_id' => 0,
                            'content' => $guideText,
                            'page_number' => null,
                        ]);
                    }
                }
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

            ActivityLog::record(
                $request->user()->id,
                'exam_generated',
                sprintf('Generated %d exam questions for project #%d', count($createdItems), $project->id),
                ['project_id' => $project->id, 'question_run_id' => $questionRun->id, 'item_count' => count($createdItems)],
                $request->ip(),
            );
        } catch (ConnectionException $exception) {
            // Gemini did not respond at all (timeout / network unreachable).
            $questionRun->update([
                'status' => 'failed',
                'failed_reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The AI took too long to respond. Please try again.',
                'error_code' => 'ai_timeout',
            ], 503);
        } catch (RequestException $exception) {
            $questionRun->update([
                'status' => 'failed',
                'failed_reason' => $exception->getMessage(),
            ]);

            if ($exception->response?->status() === 503) {
                return response()->json([
                    'message' => 'The AI is currently experiencing high demand. Please try again in a moment.',
                    'error_code' => 'ai_high_demand',
                ], 503);
            }

            throw $exception;
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
            return array_fill(0, $requestedItems, QuestionType::MultipleChoice->value);
        }

        $plan = [];
        $total = 0;

        foreach (QuestionType::values() as $type) {
            $count = (int) ($distribution[$type] ?? 0);
            $total += $count;
            $plan = array_merge($plan, array_fill(0, $count, $type));
        }

        if ($total !== $requestedItems) {
            throw ValidationException::withMessages([
                'question_type_distribution' => [
                    sprintf('Question type counts must sum to %d.', $requestedItems),
                ],
            ]);
        }

        return $plan;
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

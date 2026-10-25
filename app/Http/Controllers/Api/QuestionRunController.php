<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\QuestionType;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateQuestionRunJob;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

        return $this->generateForTosRun(
            $request,
            $project,
            $tosRun,
            $validated['question_type_distribution'] ?? $tosRun->question_type_distribution,
            $generator,
        );
    }

    public function enqueue(Request $request, Project $project): JsonResponse
    {
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
        $distribution = $validated['question_type_distribution'] ?? $tosRun->question_type_distribution;
        $questionRun = $project->questionRuns()
            ->where('tos_run_id', $tosRun->id)
            ->whereIn('status', ['processing', 'completed', 'failed'])
            ->latest('id')
            ->first();
        $shouldDispatch = false;

        if ($questionRun === null) {
            $questionRun = $this->createPendingQuestionRun($project, $tosRun, $distribution);
            $shouldDispatch = true;
        }

        if ($questionRun->status === 'failed') {
            $questionRun->update(['status' => 'processing', 'failed_reason' => null]);
            $shouldDispatch = true;
        }

        if ($shouldDispatch) {
            GenerateQuestionRunJob::dispatch(
                $questionRun->id,
                $project->id,
                $tosRun->id,
                $request->user()->id,
                $distribution,
                $request->ip(),
            );
        }

        return response()->json(['data' => $questionRun->load('tosRun')], 202);
    }

    public function storePair(Request $request, Project $project, GeminiQuestionGenerator $generator): JsonResponse
    {
        set_time_limit(0);

        $validated = $request->validate([
            'midterm_tos_run_id' => ['required_without:final_tos_run_id', 'integer'],
            'final_tos_run_id' => ['required_without:midterm_tos_run_id', 'integer', 'different:midterm_tos_run_id'],
        ]);

        $runs = [];
        foreach (['midterm', 'final'] as $term) {
            $key = "{$term}_tos_run_id";
            if (! isset($validated[$key])) {
                continue;
            }
            $runs[$term] = $this->resolveTosRun($project, $validated[$key]);
            if ($runs[$term]->term !== $term) {
                throw ValidationException::withMessages([$key => [sprintf('Provide a %s TOS run.', ucfirst($term))]]);
            }
        }

        $generated = [];
        foreach ($runs as $term => $tosRun) {
            $existing = $project->questionRuns()
                ->where('tos_run_id', $tosRun->id)
                ->where('status', 'completed')
                ->with(['items' => fn ($query) => $query->orderBy('sequence'), 'tosRun'])
                ->latest('id')
                ->first();
            if ($existing !== null) {
                $generated[$term] = $existing->toArray();

                continue;
            }

            $response = $this->generateForTosRun(
                $request,
                $project,
                $tosRun,
                $tosRun->question_type_distribution,
                $generator,
            );
            if ($response->getStatusCode() !== 201) {
                return response()->json([
                    'message' => sprintf('%s generation failed; the other term was not discarded.', ucfirst($term)),
                    'completed' => $generated,
                    'error' => $response->getData(true),
                ], $response->getStatusCode());
            }
            $generated[$term] = $response->getData(true)['data'];
        }

        return response()->json(['data' => $generated], 201);
    }

    public function enqueuePair(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'midterm_tos_run_id' => ['required_without:final_tos_run_id', 'integer'],
            'final_tos_run_id' => ['required_without:midterm_tos_run_id', 'integer', 'different:midterm_tos_run_id'],
        ]);

        $runs = [];
        foreach (['midterm', 'final'] as $term) {
            $key = "{$term}_tos_run_id";
            if (! isset($validated[$key])) {
                continue;
            }

            $tosRun = $this->resolveTosRun($project, $validated[$key]);
            if ($tosRun->term !== $term) {
                throw ValidationException::withMessages([$key => [sprintf('Provide a %s TOS run.', ucfirst($term))]]);
            }

            $questionRun = $project->questionRuns()
                ->where('tos_run_id', $tosRun->id)
                ->whereIn('status', ['processing', 'completed', 'failed'])
                ->with(['items' => fn ($query) => $query->orderBy('sequence'), 'tosRun'])
                ->latest('id')
                ->first();
            $shouldDispatch = false;

            if ($questionRun === null) {
                $questionRun = $this->createPendingQuestionRun($project, $tosRun, $tosRun->question_type_distribution);
                $shouldDispatch = true;
            }

            if ($questionRun->status === 'failed') {
                $questionRun->update(['status' => 'processing', 'failed_reason' => null]);
                $shouldDispatch = true;
            }

            if ($shouldDispatch) {
                GenerateQuestionRunJob::dispatch(
                    $questionRun->id,
                    $project->id,
                    $tosRun->id,
                    $request->user()->id,
                    $tosRun->question_type_distribution,
                    $request->ip(),
                );
            }

            $runs[$term] = $questionRun->toArray();
        }

        return response()->json(['data' => $runs], 202);
    }

    /**
     * @param  array<string, int>|null  $distribution
     */
    public function generateForTosRun(
        Request $request,
        Project $project,
        TosRun $tosRun,
        ?array $distribution,
        GeminiQuestionGenerator $generator,
        ?QuestionRun $existingQuestionRun = null,
    ): JsonResponse {
        $requestedItems = (int) $tosRun->allocations->sum('item_count');
        $questionTypePlan = $this->resolveQuestionTypePlan($requestedItems, $distribution);
        $documents = Document::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [DocumentStatus::Analyzed, DocumentStatus::TosReady])
            ->with('extractedChunks')
            ->get();

        if ($documents->isEmpty() || ! $this->hasSyllabus($documents)) {
            return response()->json([
                'message' => 'Question generation requires an analyzed syllabus.',
            ], 422);
        }

        // Prefer learning materials when supplied. Without them, generation
        // falls back to the syllabus text plus the extracted topic/objective.
        $materialDocuments = $documents
            ->filter(fn (Document $document): bool => $document->kind === DocumentKind::Material)
            ->values()
            ->all();
        $syllabusDocuments = $documents
            ->filter(fn (Document $document): bool => $document->kind === DocumentKind::Syllabus)
            ->values()
            ->all();

        // Pre-load syllabus topics so we can supplement context for any topic not
        // covered in the learning materials (used as a subject-scope guide, not a source).
        $syllabusTopics = SyllabusTopic::query()
            ->where('project_id', $project->id)
            ->when($tosRun->term !== null, fn ($query) => $query->where('term', $tosRun->term))
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'topic_name', 'hours', 'objective', 'week_number'])
            ->keyBy('id');
        $syllabusTopicsByName = $syllabusTopics->keyBy('topic_name');

        $questionRun = $existingQuestionRun ?? $project->questionRuns()->create([
            'tos_run_id' => $tosRun->id,
            'requested_items' => $requestedItems,
            'status' => 'processing',
        ]);
        $questionRun->update(['status' => 'processing', 'failed_reason' => null]);
        $generationStartedAt = hrtime(true);

        $persistedItems = $questionRun->items()->orderBy('sequence')->get();
        $sequence = $persistedItems->count() + 1;
        $createdItems = $persistedItems->map(fn ($item): array => [
            'question_text' => $item->question_text,
            'topic_name' => $item->topic_name,
            'question_type' => $item->question_type,
        ])->all();

        try {
            $requests = [];
            $contextsByRequestId = [];
            $questionTypesByRequestId = [];
            $questionTypeIndex = 0;

            foreach ($tosRun->allocations as $allocation) {
                $syllabusTopic = $allocation->syllabus_topic_id !== null
                    ? $syllabusTopics->get($allocation->syllabus_topic_id)
                    : $syllabusTopicsByName->get($allocation->topic_name);
                $topicMaterials = array_values(array_filter(
                    $materialDocuments,
                    fn (Document $material): bool => $material->syllabus_topic_id !== null
                        ? $material->syllabus_topic_id === $syllabusTopic?->id
                        : ($material->week_number === null || $material->week_number === $syllabusTopic?->week_number),
                ));
                $contexts = $generator->retrieveContext($allocation->topic_name, $topicMaterials);
                if ($contexts === []) {
                    $contexts = $generator->retrieveContext($allocation->topic_name, $syllabusDocuments);
                }

                // When no learning material explicitly mentions this topic, supplement with the
                // syllabus topic objective as a subject-scope guide so the AI knows what the
                // topic is about — without quoting from the syllabus directly.
                if (! $generator->hasTopicMatch($allocation->topic_name, $topicMaterials)) {
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

            $remainingRequests = array_slice($requests, $persistedItems->count());
            // Ollama produces one question per request, so persist each valid
            // result immediately. Remote providers can still use larger batches.
            $batchSize = strtolower((string) config('services.ai.provider', 'gemini')) === 'ollama'
                ? 1
                : max(1, (int) config('services.ai.question_batch_size', 5));

            foreach (array_chunk($remainingRequests, $batchSize) as $requestBatch) {
                if ($this->isCancelled($questionRun)) {
                    return $this->cancelledResponse($questionRun);
                }

                $batchContexts = [];
                foreach ($requestBatch as $requestSpec) {
                    $batchContexts[$requestSpec['id']] = $contextsByRequestId[$requestSpec['id']];
                }

                try {
                    $generatedByRequest = $generator->generateBatch($requestBatch, $batchContexts);
                } catch (ConnectionException|RequestException $exception) {
                    throw $exception;
                } catch (\Throwable) {
                    // Invalid batch-shaped output falls back to individual generation.
                    $generatedByRequest = [];
                }

                if ($this->isCancelled($questionRun)) {
                    return $this->cancelledResponse($questionRun);
                }

                $batchItems = [];
                foreach ($requestBatch as $requestSpec) {
                    $requestId = $requestSpec['id'];
                    $generated = $generatedByRequest[$requestId] ?? null;
                    $generatedQuestionType = $questionTypesByRequestId[$requestId];

                    if (! is_array($generated)) {
                        try {
                            $generated = $generator->generateOne(
                                $requestSpec['topic_name'],
                                $requestSpec['bloom_level'],
                                $generatedQuestionType,
                                $contextsByRequestId[$requestId],
                                $createdItems,
                            );
                        } catch (\RuntimeException $exception) {
                            $isExhaustedDuplicate = str_contains(
                                $exception->getMessage(),
                                'duplicates an existing exam item',
                            );
                            if (! $isExhaustedDuplicate) {
                                throw $exception;
                            }

                            if ($generatedQuestionType === QuestionType::MatchingType->value) {
                                $generatedQuestionType = QuestionType::MultipleChoice->value;
                            }

                            Log::warning('Falling back to Gemini after local model duplicate exhaustion.', [
                                'question_run_id' => $questionRun->id,
                                'topic' => $requestSpec['topic_name'],
                                'bloom_level' => $requestSpec['bloom_level'],
                                'question_type' => $generatedQuestionType,
                            ]);

                            $generated = $generator->generateOne(
                                $requestSpec['topic_name'],
                                $requestSpec['bloom_level'],
                                $generatedQuestionType,
                                $contextsByRequestId[$requestId],
                                $createdItems,
                                'gemini',
                            );
                        }
                    }

                    if ($this->isCancelled($questionRun)) {
                        return $this->cancelledResponse($questionRun);
                    }

                    $item = [
                        'topic_name' => $requestSpec['topic_name'],
                        'bloom_level' => $requestSpec['bloom_level'],
                        'question_type' => $generatedQuestionType,
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
                    $batchItems[] = $item;
                    $createdItems[] = $item;
                }

                if ($this->isCancelled($questionRun)) {
                    return $this->cancelledResponse($questionRun);
                }

                DB::transaction(function () use ($questionRun, $batchItems): void {
                    $questionRun->items()->createMany($batchItems);
                    $questionRun->update([
                        'generated_items' => $questionRun->items()->count(),
                        'status' => 'processing',
                        'failed_reason' => null,
                    ]);
                });
            }

            if ($this->isCancelled($questionRun)) {
                return $this->cancelledResponse($questionRun);
            }

            $questionRun->update([
                'generated_items' => $questionRun->items()->count(),
                'status' => 'completed',
                'failed_reason' => null,
            ]);

            ActivityLog::record(
                $request->user()->id,
                'exam_generated',
                sprintf('Generated %d exam questions for project #%d', $questionRun->generated_items, $project->id),
                [
                    'project_id' => $project->id,
                    'question_run_id' => $questionRun->id,
                    'item_count' => $questionRun->generated_items,
                    'generation_duration_ms' => $this->elapsedMilliseconds($generationStartedAt),
                ],
                $request->ip(),
            );
        } catch (ConnectionException $exception) {
            if ($this->isCancelled($questionRun)) {
                return $this->cancelledResponse($questionRun);
            }

            // Gemini did not respond at all (timeout / network unreachable).
            $questionRun->update([
                'status' => 'failed',
                'failed_reason' => $exception->getMessage(),
            ]);

            $unreachable = Str::contains($exception->getMessage(), [
                'Failed to connect',
                'Connection refused',
                'Could not connect',
            ], true);

            return response()->json([
                'message' => $unreachable
                    ? 'Neither Ollama AI PC is reachable. Start Ollama and confirm Tailscale is connected on both PCs.'
                    : 'The AI took too long to respond. Please try again.',
                'error_code' => $unreachable ? 'ai_unavailable' : 'ai_timeout',
            ], 503);
        } catch (RequestException $exception) {
            if ($this->isCancelled($questionRun)) {
                return $this->cancelledResponse($questionRun);
            }

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
            if ($this->isCancelled($questionRun)) {
                return $this->cancelledResponse($questionRun);
            }

            $questionRun->update([
                'status' => 'failed',
                'failed_reason' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            $durationMs = $this->elapsedMilliseconds($generationStartedAt);
            $finishedRun = $questionRun->fresh();

            Log::info('Exam generation finished.', [
                'project_id' => $project->id,
                'question_run_id' => $questionRun->id,
                'tos_run_id' => $tosRun->id,
                'term' => $tosRun->term,
                'item_count' => $finishedRun->generated_items,
                'status' => $finishedRun->status,
                'duration_ms' => $durationMs,
                'duration_seconds' => round($durationMs / 1000, 3),
            ]);
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

    public function cancelActive(Project $project): JsonResponse
    {
        $runs = $project->questionRuns()
            ->where('status', 'processing')
            ->get();

        foreach ($runs as $run) {
            $run->update([
                'status' => 'cancelled',
                'failed_reason' => 'Cancelled by user.',
            ]);
        }

        return response()->json([
            'data' => [
                'cancelled_count' => $runs->count(),
                'question_run_ids' => $runs->pluck('id')->values(),
            ],
        ]);
    }

    private function isCancelled(QuestionRun $questionRun): bool
    {
        return $questionRun->fresh()->status === 'cancelled';
    }

    private function cancelledResponse(QuestionRun $questionRun): JsonResponse
    {
        return response()->json([
            'message' => 'Question generation was cancelled.',
            'data' => $questionRun->fresh(['items', 'tosRun']),
        ], 409);
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

    /** @param array<string, int>|null $distribution */
    private function createPendingQuestionRun(Project $project, TosRun $tosRun, ?array $distribution): QuestionRun
    {
        $requestedItems = (int) $tosRun->allocations->sum('item_count');
        $this->resolveQuestionTypePlan($requestedItems, $distribution);

        return $project->questionRuns()->create([
            'tos_run_id' => $tosRun->id,
            'requested_items' => $requestedItems,
            'generated_items' => 0,
            'status' => 'processing',
        ]);
    }

    /**
     * @param  Collection<int, Document>  $documents
     */
    private function hasSyllabus(Collection $documents): bool
    {
        return $documents->contains(fn (Document $document): bool => $document->kind === DocumentKind::Syllabus);
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}

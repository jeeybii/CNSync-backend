<?php

namespace App\Http\Controllers\Api;

use App\Enums\BloomLevel;
use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\Project;
use App\Models\SyllabusTopic;
use App\Services\ExamSuggestionService;
use App\Services\SyllabusExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $documents = $project->documents()
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $documents]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:65536', 'mimes:pdf,doc,docx,txt,pptx'],
            'kind' => ['required', Rule::enum(DocumentKind::class)],
            'syllabus_topic_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $file = $validated['file'];
        $kind = DocumentKind::from($validated['kind']);

        $singleUploadError = $this->validateProjectDocumentCapacity($project, $kind, 1);
        if ($singleUploadError !== null) {
            return $singleUploadError;
        }

        $topic = null;
        if ($kind === DocumentKind::Material) {
            $topic = SyllabusTopic::query()
                ->where('project_id', $project->id)
                ->whereHas('document', fn ($query) => $query
                    ->where('kind', DocumentKind::Syllabus)
                    ->where('status', DocumentStatus::TosReady))
                ->find($validated['syllabus_topic_id'] ?? 0);

            if ($topic === null) {
                return response()->json([
                    'message' => 'Extract the syllabus first, then upload each learning material to a syllabus topic.',
                ], 422);
            }
        }
        $document = $this->createDocument($project, $file, $kind, $topic);

        return response()->json(['data' => $document->fresh()], 201);
    }

    public function storeBundle(Request $request, Project $project): JsonResponse
    {
        return response()->json([
            'message' => 'Upload and extract the syllabus first, then upload materials to its topics.',
        ], 422);
    }

    public function storeTopicMaterials(Request $request, Project $project, SyllabusTopic $syllabusTopic): JsonResponse
    {
        if ($syllabusTopic->project_id !== $project->id
            || $syllabusTopic->document?->kind !== DocumentKind::Syllabus
            || $syllabusTopic->document?->status !== DocumentStatus::TosReady) {
            return response()->json([
                'message' => 'Extract the syllabus first, then upload materials to one of its topics.',
            ], 422);
        }

        $validated = $request->validate([
            'materials' => ['required', 'array', 'min:1', 'max:10'],
            'materials.*' => ['required', 'file', 'max:65536', 'mimes:pdf,doc,docx,txt,pptx'],
        ]);

        $capacityError = $this->validateProjectDocumentCapacity($project, DocumentKind::Material, count($validated['materials']));
        if ($capacityError !== null) {
            return $capacityError;
        }

        $materials = collect($validated['materials'])
            ->map(fn ($file) => $this->createDocument($project, $file, DocumentKind::Material, $syllabusTopic)->fresh())
            ->values();

        return response()->json(['data' => $materials], 201);
    }

    public function show(Project $project, Document $document): JsonResponse
    {
        return response()->json(['data' => $document]);
    }

    public function assignTopic(Request $request, Project $project, Document $document): JsonResponse
    {
        if ($document->kind !== DocumentKind::Material) {
            return response()->json(['message' => 'Only learning materials can be assigned to a syllabus topic.'], 422);
        }

        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'integer', 'min:1'],
        ]);

        $topic = SyllabusTopic::query()
            ->where('project_id', $project->id)
            ->whereHas('document', fn ($query) => $query
                ->where('kind', DocumentKind::Syllabus)
                ->where('status', DocumentStatus::TosReady))
            ->find($validated['syllabus_topic_id']);

        if ($topic === null) {
            return response()->json(['message' => 'This topic is not part of an extracted syllabus in the project.'], 422);
        }

        $document->update([
            'syllabus_topic_id' => $topic->id,
            'week_number' => $topic->week_number,
        ]);

        return response()->json(['data' => $document->fresh()]);
    }

    public function transitions(Project $project, Document $document): JsonResponse
    {
        $transitions = $document->statusTransitions()
            ->orderBy('transitioned_at')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $transitions]);
    }

    public function syllabusTopics(Project $project, Document $document): JsonResponse
    {
        if ($document->kind !== DocumentKind::Syllabus) {
            return response()->json([
                'message' => 'Only syllabus documents have syllabus topics.',
            ], 422);
        }

        return response()->json([
            'data' => $document->syllabusTopics()
                ->with(['materials' => fn ($query) => $query->orderBy('id')])
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            'suggested_bloom_levels' => $document->suggested_bloom_levels,
            'suggested_bloom_levels_by_term' => $document->suggested_bloom_levels_by_term,
            'suggested_question_type_percentages_by_term' => $document->suggested_question_type_percentages_by_term,
        ]);
    }

    public function extractSyllabus(
        Project $project,
        Document $document,
        SyllabusExtractionService $extractor
    ): JsonResponse {
        // AI extraction can exceed the default 30s execution limit during model backoff/retries.
        set_time_limit(180);

        if ($document->kind !== DocumentKind::Syllabus) {
            return response()->json([
                'message' => 'Only syllabus documents can be processed by syllabus extraction.',
            ], 422);
        }

        if ($document->status !== DocumentStatus::Analyzed && $document->status !== DocumentStatus::TosReady) {
            return response()->json([
                'message' => 'Document must be in analyzed state before syllabus extraction.',
            ], 422);
        }

        $lock = Cache::lock(sprintf('extract-syllabus-document-%d', $document->id), 300);
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Syllabus extraction is already in progress for this document.',
            ], 409);
        }

        try {
            if ($document->syllabusTopics()->whereHas('materials')->exists()) {
                return response()->json([
                    'message' => 'Remove topic materials before re-extracting the syllabus so their topic assignments are not lost.',
                ], 409);
            }

            $extraction = $extractor->extractWithSuggestions($document);
            $topics = $extraction['topics'];

            DB::transaction(function () use ($project, $document, $topics, $extraction): void {
                $document->syllabusTopics()->delete();

                $document->update([
                    'suggested_bloom_levels' => $extraction['suggested_bloom_levels'],
                    'suggested_bloom_levels_by_term' => $extraction['suggested_bloom_levels_by_term'],
                    'suggested_question_type_percentages_by_term' => $extraction['suggested_question_type_percentages_by_term'],
                ]);

                $document->syllabusTopics()->createMany(array_map(
                    fn (array $topic, int $index): array => [
                        'project_id' => $project->id,
                        'topic_name' => $topic['topic_name'],
                        'hours' => $topic['hours'],
                        'objective' => $topic['objective'],
                        'bloom_level' => $topic['bloom_level'],
                        'confidence' => $topic['confidence'],
                        'term' => $topic['term'],
                        'week_number' => $topic['week_number'],
                        'position' => $index + 1,
                    ],
                    $topics,
                    array_keys($topics),
                ));

                if ($document->status === DocumentStatus::Analyzed) {
                    $document->transitionTo(DocumentStatus::TosReady);
                }
            });
        } finally {
            $lock->release();
        }

        return response()->json([
            'data' => $document->syllabusTopics()
                ->with('materials')
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            'suggested_bloom_levels' => $document->fresh()->suggested_bloom_levels,
            'suggested_bloom_levels_by_term' => $document->suggested_bloom_levels_by_term,
            'suggested_question_type_percentages_by_term' => $document->suggested_question_type_percentages_by_term,
        ]);
    }

    public function updateSyllabusTopics(Request $request, Project $project, Document $document, ExamSuggestionService $suggestions): JsonResponse
    {
        if ($document->kind !== DocumentKind::Syllabus) {
            return response()->json([
                'message' => 'Only syllabus documents can update syllabus topics.',
            ], 422);
        }

        $validated = $request->validate([
            'topics' => ['required', 'array', 'min:1'],
            'topics.*.id' => ['required', 'integer'],
            'topics.*.topic_name' => ['required', 'string', 'max:255'],
            'topics.*.hours' => ['required', 'numeric', 'gt:0'],
            'topics.*.objective' => ['nullable', 'string', 'max:5000'],
            'topics.*.bloom_level' => ['nullable', 'string', Rule::in(BloomLevel::values())],
            'topics.*.term' => ['nullable', 'string', Rule::in(['midterm', 'final'])],
            'topics.*.week_number' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $topicsById = $document->syllabusTopics()
            ->whereIn('id', collect($validated['topics'])->pluck('id')->all())
            ->get()
            ->keyBy('id');

        if ($topicsById->count() !== count($validated['topics'])) {
            return response()->json([
                'message' => 'One or more syllabus topic rows do not belong to this document.',
            ], 422);
        }

        $weekNumbers = collect($validated['topics'])
            ->map(fn (array $payload): ?int => $payload['week_number'] ?? $topicsById->get($payload['id'])?->week_number)
            ->filter(fn (?int $week): bool => $week !== null);
        if ($weekNumbers->count() !== $weekNumbers->unique()->count()) {
            throw ValidationException::withMessages([
                'topics' => ['Each main topic must have its own week number.'],
            ]);
        }

        DB::transaction(function () use ($validated, $topicsById, $document, $suggestions): void {
            $refreshSuggestions = false;
            foreach ($validated['topics'] as $payload) {
                /** @var SyllabusTopic $topic */
                $topic = $topicsById->get($payload['id']);
                $topic->update([
                    'topic_name' => $payload['topic_name'],
                    'hours' => (float) $payload['hours'],
                    'objective' => array_key_exists('objective', $payload) ? $payload['objective'] : $topic->objective,
                    'bloom_level' => array_key_exists('bloom_level', $payload) ? $payload['bloom_level'] : $topic->bloom_level?->value,
                    'term' => $payload['term'] ?? $topic->term,
                    'week_number' => $payload['week_number'] ?? $topic->week_number,
                ]);
                $refreshSuggestions = $refreshSuggestions || $topic->wasChanged(['topic_name', 'hours', 'objective', 'bloom_level', 'term']);
                if (array_key_exists('week_number', $payload)) {
                    $topic->materials()->update(['week_number' => $topic->week_number]);
                }
            }

            if (! $refreshSuggestions) {
                return;
            }

            $allTopics = $document->syllabusTopics()->orderBy('position')->orderBy('id')->get();
            $bloomByTerm = [];
            $questionTypesByTerm = [];
            foreach (['midterm', 'final'] as $term) {
                $termTopics = $allTopics->where('term', $term)->map(fn (SyllabusTopic $topic): array => [
                    'bloom_level' => $topic->bloom_level?->value,
                    'hours' => $topic->hours,
                ])->values()->all();
                $bloomByTerm[$term] = $suggestions->bloomLevels($termTopics);
                $questionTypesByTerm[$term] = $suggestions->questionTypePercentages(null, $termTopics);
            }
            $document->update([
                'suggested_bloom_levels_by_term' => $bloomByTerm,
                'suggested_question_type_percentages_by_term' => $questionTypesByTerm,
                'suggested_bloom_levels' => array_slice(array_values(array_unique(array_merge($bloomByTerm['midterm'], $bloomByTerm['final']))), 0, 3),
            ]);
        });

        return response()->json([
            'data' => $document->syllabusTopics()
                ->with('materials')
                ->orderBy('position')
                ->orderBy('id')
                ->get(),
            'suggested_bloom_levels_by_term' => $document->suggested_bloom_levels_by_term,
            'suggested_question_type_percentages_by_term' => $document->suggested_question_type_percentages_by_term,
        ]);
    }

    public function destroy(Project $project, Document $document): JsonResponse
    {
        if ($document->kind === DocumentKind::Syllabus && $document->syllabusTopics()->whereHas('materials')->exists()) {
            return response()->json([
                'message' => 'Remove topic materials before deleting their syllabus.',
            ], 409);
        }

        if (Storage::disk($document->disk)->exists($document->path)) {
            Storage::disk($document->disk)->delete($document->path);
        }

        $document->delete();

        return response()->json(null, 204);
    }

    private function createDocument(Project $project, mixed $file, DocumentKind $kind, ?SyllabusTopic $topic = null): Document
    {
        $directory = 'documents/'.$project->id;
        $path = $file->store($directory, 'local');

        $document = $project->documents()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'kind' => $kind,
            'week_number' => $topic?->week_number,
            'syllabus_topic_id' => $topic?->id,
            'status' => DocumentStatus::Uploaded,
        ]);

        ProcessDocumentJob::dispatch($document->id);

        return $document;
    }

    private function validateProjectDocumentCapacity(Project $project, DocumentKind $kind, int $newMaterialsCount): ?JsonResponse
    {
        if ($project->documents()->where('kind', DocumentKind::Syllabus)->exists() && $kind === DocumentKind::Syllabus) {
            return response()->json([
                'message' => 'Only one syllabus document is allowed per project.',
            ], 422);
        }

        $materialCount = $project->documents()->where('kind', DocumentKind::Material)->count();
        if (($materialCount + $newMaterialsCount) > 100) {
            return response()->json([
                'message' => 'A project can have at most 100 learning material documents.',
            ], 422);
        }

        return null;
    }
}

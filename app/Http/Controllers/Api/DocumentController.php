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
use App\Services\SyllabusExtractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
        ]);

        $file = $validated['file'];
        $kind = DocumentKind::from($validated['kind']);

        $singleUploadError = $this->validateProjectDocumentCapacity($project, $kind, 1);
        if ($singleUploadError !== null) {
            return $singleUploadError;
        }

        $document = $this->createDocument($project, $file, $kind);

        return response()->json(['data' => $document->fresh()], 201);
    }

    public function storeBundle(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'syllabus' => ['required', 'file', 'max:65536', 'mimes:pdf,doc,docx,txt,pptx'],
            'materials' => ['required', 'array', 'min:1', 'max:10'],
            'materials.*' => ['required', 'file', 'max:65536', 'mimes:pdf,doc,docx,txt,pptx'],
        ]);

        $capacityError = $this->validateProjectDocumentCapacity(
            $project,
            DocumentKind::Syllabus,
            count($validated['materials']),
        );
        if ($capacityError !== null) {
            return $capacityError;
        }

        $syllabusDocument = $this->createDocument($project, $validated['syllabus'], DocumentKind::Syllabus);
        $materialDocuments = collect($validated['materials'])
            ->map(fn ($file) => $this->createDocument($project, $file, DocumentKind::Material)->fresh())
            ->values();

        return response()->json([
            'data' => [
                'syllabus' => $syllabusDocument->fresh(),
                'materials' => $materialDocuments,
            ],
        ], 201);
    }

    public function show(Project $project, Document $document): JsonResponse
    {
        return response()->json(['data' => $document]);
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
                ->orderBy('id')
                ->get(),
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
            $topics = $extractor->extract($document);

            DB::transaction(function () use ($project, $document, $topics): void {
                $document->syllabusTopics()->delete();

                $document->syllabusTopics()->createMany(array_map(
                    fn (array $topic): array => [
                        'project_id' => $project->id,
                        'topic_name' => $topic['topic_name'],
                        'hours' => $topic['hours'],
                        'objective' => $topic['objective'],
                        'bloom_level' => $topic['bloom_level'],
                        'confidence' => $topic['confidence'],
                    ],
                    $topics,
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
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function updateSyllabusTopics(Request $request, Project $project, Document $document): JsonResponse
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

        DB::transaction(function () use ($validated, $topicsById): void {
            foreach ($validated['topics'] as $payload) {
                /** @var SyllabusTopic $topic */
                $topic = $topicsById->get($payload['id']);
                $topic->update([
                    'topic_name' => $payload['topic_name'],
                    'hours' => (float) $payload['hours'],
                    'objective' => $payload['objective'] ?? null,
                    'bloom_level' => $payload['bloom_level'] ?? null,
                ]);
            }
        });

        return response()->json([
            'data' => $document->syllabusTopics()
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function destroy(Project $project, Document $document): JsonResponse
    {
        if (Storage::disk($document->disk)->exists($document->path)) {
            Storage::disk($document->disk)->delete($document->path);
        }

        $document->delete();

        return response()->json(null, 204);
    }

    private function createDocument(Project $project, mixed $file, DocumentKind $kind): Document
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
        if (($materialCount + $newMaterialsCount) > 10) {
            return response()->json([
                'message' => 'A project can have at most 10 learning material documents.',
            ], 422);
        }

        return null;
    }
}

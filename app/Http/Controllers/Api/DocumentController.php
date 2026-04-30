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
            'file' => ['required', 'file', 'max:51200', 'mimes:pdf,doc,docx'],
            'kind' => ['required', Rule::enum(DocumentKind::class)],
        ]);

        $file = $validated['file'];
        $kind = DocumentKind::from($validated['kind']);

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

        return response()->json(['data' => $document->fresh()], 201);
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
                ->orderByDesc('hours')
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

        return response()->json([
            'data' => $document->syllabusTopics()
                ->orderByDesc('hours')
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
                ->orderByDesc('hours')
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
}

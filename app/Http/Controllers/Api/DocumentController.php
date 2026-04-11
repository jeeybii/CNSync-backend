<?php

namespace App\Http\Controllers\Api;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function destroy(Project $project, Document $document): JsonResponse
    {
        if (Storage::disk($document->disk)->exists($document->path)) {
            Storage::disk($document->disk)->delete($document->path);
        }

        $document->delete();

        return response()->json(null, 204);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateStudentDeckJob;
use App\Models\StudentDeck;
use App\Services\StudentEssayGrader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class StudentDeckController extends Controller
{
    public function gradeEssay(Request $request, StudentDeck $studentDeck, StudentEssayGrader $grader): JsonResponse
    {
        set_time_limit(60);
        $this->authorizeOwner($request, $studentDeck);
        $validated = $request->validate([
            'sequence' => ['required', 'integer', 'min:1'],
            'answer' => ['required', 'string', 'max:10000'],
        ]);

        $question = collect($studentDeck->questions ?? [])->first(
            fn ($item): bool => (int) ($item['sequence'] ?? 0) === (int) $validated['sequence']
        );
        if (! is_array($question) || ($question['question_type'] ?? null) !== 'essay') {
            return response()->json(['message' => 'Essay question not found in this deck.'], 404);
        }

        return response()->json(['data' => $grader->grade(
            (string) $question['question_text'],
            (string) $question['answer_key'],
            $validated['answer'],
        )]);
    }

    public function index(Request $request): JsonResponse
    {
        $decks = StudentDeck::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'status', 'number_of_items', 'created_at', 'updated_at']);

        return response()->json(['data' => $decks]);
    }

    public function show(Request $request, StudentDeck $studentDeck): JsonResponse
    {
        $this->authorizeOwner($request, $studentDeck);

        return response()->json(['data' => $studentDeck]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'number_of_items' => ['required', 'integer', 'min:1', 'max:100'],
            'question_type_distribution' => ['sometimes', 'array'],
            'question_type_distribution.multiple_choice' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.true_false' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.identification' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.essay' => ['sometimes', 'integer', 'min:0'],
            'materials' => ['required', 'array', 'min:1', 'max:5'],
            'materials.*' => ['required', 'file', 'max:20480'], // 20 MB per file
        ]);

        // Validate distribution sum if provided
        if (isset($validated['question_type_distribution'])) {
            $sum = array_sum($validated['question_type_distribution']);
            if ($sum !== (int) $validated['number_of_items']) {
                throw ValidationException::withMessages([
                    'question_type_distribution' => [
                        sprintf('Question type distribution must sum to %d.', $validated['number_of_items']),
                    ],
                ]);
            }
        }

        $deck = StudentDeck::query()->create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'status' => 'processing',
            'number_of_items' => (int) $validated['number_of_items'],
            'questions' => null,
            'failed_reason' => null,
        ]);

        $materials = [];
        try {
            foreach ($validated['materials'] as $file) {
                $extension = $file->getClientOriginalExtension();
                $storedName = Str::uuid()->toString().($extension === '' ? '' : '.'.$extension);
                $path = $file->storeAs('student-decks/'.$deck->id, $storedName, 'local');
                if ($path === false) {
                    throw new \RuntimeException(sprintf('Could not store file "%s".', $file->getClientOriginalName()));
                }

                $materials[] = [
                    'path' => $path,
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType(),
                ];
            }

            GenerateStudentDeckJob::dispatch(
                $deck->id,
                $materials,
                (int) $validated['number_of_items'],
                $validated['question_type_distribution'] ?? null,
                $request->ip(),
            );
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory('student-decks/'.$deck->id);
            $deck->delete();

            throw ValidationException::withMessages([
                'materials' => [$exception->getMessage()],
            ]);
        }

        return response()->json(['data' => $deck->fresh()], 202);
    }

    public function destroy(Request $request, StudentDeck $studentDeck): JsonResponse
    {
        $this->authorizeOwner($request, $studentDeck);
        $studentDeck->delete();

        return response()->json(null, 204);
    }

    private function authorizeOwner(Request $request, StudentDeck $deck): void
    {
        if ($deck->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this deck.');
        }
    }
}

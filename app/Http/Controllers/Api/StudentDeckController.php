<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\StudentDeck;
use App\Services\StudentDeckGeneratorService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StudentDeckController extends Controller
{
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

    public function store(Request $request, StudentDeckGeneratorService $generator): JsonResponse
    {
        // Generation can take a while for large files.
        set_time_limit(0);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'number_of_items' => ['required', 'integer', 'min:1', 'max:100'],
            'question_type_distribution' => ['sometimes', 'array'],
            'question_type_distribution.multiple_choice' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.true_false' => ['sometimes', 'integer', 'min:0'],
            'question_type_distribution.identification' => ['sometimes', 'integer', 'min:0'],
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

        // Extract text from all uploaded materials
        $allChunks = [];
        foreach ($validated['materials'] as $file) {
            try {
                $text = $generator->extractTextFromFile($file);
                $allChunks = array_merge($allChunks, $generator->chunkText($text));
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    'materials' => [sprintf('Could not read file "%s": %s', $file->getClientOriginalName(), $e->getMessage())],
                ]);
            }
        }

        if (empty($allChunks)) {
            throw ValidationException::withMessages([
                'materials' => ['No readable text content found in the uploaded files.'],
            ]);
        }

        // Generate questions with hints
        try {
            $questions = $generator->generate(
                $allChunks,
                (int) $validated['number_of_items'],
                $validated['question_type_distribution'] ?? null,
            );
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'The AI took too long to respond. Please try again.',
                'error_code' => 'ai_timeout',
            ], 503);
        } catch (RequestException $e) {
            if ($e->response?->status() === 503) {
                return response()->json([
                    'message' => 'The AI is currently experiencing high demand. Please try again in a moment.',
                    'error_code' => 'ai_high_demand',
                ], 503);
            }
            throw $e;
        }

        $deck = StudentDeck::query()->create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'status' => 'ready',
            'number_of_items' => count($questions),
            'questions' => $questions,
        ]);

        ActivityLog::record(
            $request->user()->id,
            'reviewer_deck_generated',
            sprintf('Student generated reviewer deck "%s" (%d questions)', $deck->title, $deck->number_of_items),
            ['student_deck_id' => $deck->id, 'item_count' => $deck->number_of_items],
            $request->ip(),
        );

        return response()->json(['data' => $deck], 201);
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

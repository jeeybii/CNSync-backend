<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudentDeck;
use App\Models\StudentStudySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentStudySessionController extends Controller
{
    /**
     * Start a new study session for a deck.
     * If an incomplete session already exists for this deck, it is returned instead.
     */
    public function store(Request $request, StudentDeck $studentDeck): JsonResponse
    {
        $this->authorizeOwner($request, $studentDeck);

        if ($studentDeck->status !== 'ready') {
            return response()->json([
                'message' => 'Deck is not ready for studying.',
            ], 422);
        }

        // Reuse an existing incomplete session rather than creating duplicates
        $existing = StudentStudySession::query()
            ->where('user_id', $request->user()->id)
            ->where('student_deck_id', $studentDeck->id)
            ->whereNull('completed_at')
            ->latest()
            ->first();

        if ($existing !== null) {
            return response()->json(['data' => $existing]);
        }

        $session = StudentStudySession::query()->create([
            'user_id' => $request->user()->id,
            'student_deck_id' => $studentDeck->id,
            'total_cards' => $studentDeck->number_of_items,
            'cards_completed' => 0,
            'hints_used' => 0,
            'reveals_used' => 0,
            'xp_earned' => 0,
            'completed_at' => null,
        ]);

        return response()->json(['data' => $session], 201);
    }

    /**
     * Update session progress (called by the frontend as the student advances through cards).
     */
    public function update(Request $request, StudentDeck $studentDeck, StudentStudySession $session): JsonResponse
    {
        $this->authorizeOwner($request, $studentDeck);

        if ($session->user_id !== $request->user()->id || $session->student_deck_id !== $studentDeck->id) {
            abort(403);
        }

        $validated = $request->validate([
            'cards_completed' => ['sometimes', 'integer', 'min:0'],
            'hints_used' => ['sometimes', 'integer', 'min:0'],
            'reveals_used' => ['sometimes', 'integer', 'min:0'],
            'xp_earned' => ['sometimes', 'integer', 'min:0'],
            'completed' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($session, $validated): void {
            $session->cards_completed = $validated['cards_completed'] ?? $session->cards_completed;
            $session->hints_used = $validated['hints_used'] ?? $session->hints_used;
            $session->reveals_used = $validated['reveals_used'] ?? $session->reveals_used;
            $session->xp_earned = $validated['xp_earned'] ?? $session->xp_earned;

            if (! empty($validated['completed']) && $session->completed_at === null) {
                $session->completed_at = now();
            }

            $session->save();
        });

        return response()->json(['data' => $session->fresh()]);
    }

    /**
     * List all sessions for a deck (for the student's history/progress view).
     */
    public function index(Request $request, StudentDeck $studentDeck): JsonResponse
    {
        $this->authorizeOwner($request, $studentDeck);

        $sessions = StudentStudySession::query()
            ->where('user_id', $request->user()->id)
            ->where('student_deck_id', $studentDeck->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $sessions]);
    }

    private function authorizeOwner(Request $request, StudentDeck $deck): void
    {
        if ($deck->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this deck.');
        }
    }
}

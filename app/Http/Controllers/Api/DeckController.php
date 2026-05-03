<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Deck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeckController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Deck::query();
        if ($user->role === UserRole::Student) {
            $query->where('visibility', 'public');
        } elseif ($user->role === UserRole::Faculty) {
            $query->where('user_id', $user->id);
        } else {
            abort(403);
        }

        $decks = $query->latest()->get();

        return response()->json([
            'data' => $decks->map(fn (Deck $d): array => $this->serializeDeck($d)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'array'],
            'visibility' => ['required', Rule::in(['private', 'public'])],
            'course_code' => ['nullable', 'string', 'max:64'],
            'course_title' => ['nullable', 'string', 'max:255'],
            'faculty_name' => ['nullable', 'string', 'max:255'],
            'exam_type' => ['required', 'string', 'max:64'],
            'number_of_items' => ['required', 'integer', 'min:0'],
            'tos' => ['required', 'array'],
            'questions' => ['required', 'array'],
            'source_assessment_id' => ['nullable', 'integer', 'exists:assessments,id'],
        ]);

        $user = $request->user();

        if (isset($validated['source_assessment_id'])) {
            $owns = Assessment::query()
                ->where('user_id', $user->id)
                ->whereKey($validated['source_assessment_id'])
                ->exists();
            abort_unless($owns, 422);
        }

        $deck = Deck::query()->create([
            'user_id' => $user->id,
            'source_assessment_id' => $validated['source_assessment_id'] ?? null,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'visibility' => $validated['visibility'],
            'course_code' => $validated['course_code'] ?? null,
            'course_title' => $validated['course_title'] ?? null,
            'faculty_name' => $validated['faculty_name'] ?? null,
            'exam_type' => $validated['exam_type'],
            'number_of_items' => $validated['number_of_items'],
            'tos' => $validated['tos'],
            'questions' => $validated['questions'],
        ]);

        return response()->json([
            'data' => $this->serializeDeck($deck),
        ], 201);
    }

    public function show(Request $request, Deck $deck): JsonResponse
    {
        $this->authorizeDeckView($request, $deck);

        return response()->json([
            'data' => $this->serializeDeck($deck),
        ]);
    }

    public function destroy(Request $request, Deck $deck): JsonResponse
    {
        abort_unless((int) $deck->user_id === (int) $request->user()->id, 404);
        $deck->delete();

        return response()->json(null, 204);
    }

    private function authorizeDeckView(Request $request, Deck $deck): void
    {
        $user = $request->user();
        $owns = (int) $deck->user_id === (int) $user->id;
        $public = $deck->visibility === 'public';
        $allowPublic = $public && in_array($user->role, [UserRole::Student, UserRole::Faculty], true);
        abort_unless($owns || $allowPublic, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDeck(Deck $deck): array
    {
        return [
            'id' => $deck->id,
            'created_at' => $deck->created_at?->toIso8601String(),
            'updated_at' => $deck->updated_at?->toIso8601String(),
            'title' => $deck->title,
            'description' => $deck->description ?? '',
            'tags' => $deck->tags ?? [],
            'visibility' => $deck->visibility,
            'course_code' => $deck->course_code ?? '',
            'course_title' => $deck->course_title ?? '',
            'faculty_name' => $deck->faculty_name ?? '',
            'exam_type' => $deck->exam_type,
            'number_of_items' => $deck->number_of_items,
            'tos' => $deck->tos,
            'questions' => $deck->questions,
            'source_assessment_id' => $deck->source_assessment_id,
        ];
    }
}

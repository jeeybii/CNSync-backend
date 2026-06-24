<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityLogController extends Controller
{
    /** Known action values for filter validation. */
    private const array KNOWN_ACTIONS = [
        'login',
        'logout',
        'upload',
        'exam_generated',
        'reviewer_deck_generated',
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['sometimes', 'string', 'in:'.implode(',', self::KNOWN_ACTIONS)],
            'user_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = ActivityLog::query()
            ->with('user:id,first_name,last_name,email,role')
            ->orderByDesc('created_at');

        if (! blank($validated['action'] ?? null)) {
            $query->where('action', $validated['action']);
        }

        if (! blank($validated['user_id'] ?? null)) {
            $query->where('user_id', $validated['user_id']);
        }

        $perPage = (int) ($validated['per_page'] ?? 50);
        $paginated = $query->paginate($perPage);

        return response()->json(['data' => $paginated]);
    }

    public function actions(): JsonResponse
    {
        return response()->json(['data' => self::KNOWN_ACTIONS]);
    }
}

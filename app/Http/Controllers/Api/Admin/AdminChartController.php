<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Assessment;
use App\Models\StudentDeck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AdminChartController extends Controller
{
    /**
     * Daily counts of generated assessments and reviewer decks over the last N days.
     * GET /admin/charts/generation-daily?days=30
     */
    public function generationDaily(Request $request): JsonResponse
    {
        $days = $this->clampDays($request->query('days'));
        $from = Carbon::now()->subDays($days - 1)->startOfDay();

        $assessments = Assessment::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $reviewerDecks = StudentDeck::query()
            ->where('status', 'ready')
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        return response()->json(['data' => $this->buildDailyRange($days, function (string $date) use ($assessments, $reviewerDecks): array {
            return [
                'date' => $date,
                'assessments' => (int) ($assessments[$date] ?? 0),
                'reviewer_decks' => (int) ($reviewerDecks[$date] ?? 0),
            ];
        })]);
    }

    /**
     * Daily unique login counts split by role over the last N days.
     * GET /admin/charts/active-users?days=30
     */
    public function activeUsersDaily(Request $request): JsonResponse
    {
        $days = $this->clampDays($request->query('days'));
        $from = Carbon::now()->subDays($days - 1)->startOfDay();

        $rows = ActivityLog::query()
            ->join('users', 'activity_logs.user_id', '=', 'users.id')
            ->where('activity_logs.action', 'login')
            ->where('activity_logs.created_at', '>=', $from)
            ->selectRaw('DATE(activity_logs.created_at) as date, users.role, COUNT(DISTINCT activity_logs.user_id) as count')
            ->groupBy('date', 'users.role')
            ->get();

        $byDate = [];
        foreach ($rows as $row) {
            $date = $row->date;
            if (! isset($byDate[$date])) {
                $byDate[$date] = ['faculty' => 0, 'students' => 0];
            }
            $role = strtolower((string) $row->role);
            if ($role === 'faculty') {
                $byDate[$date]['faculty'] = (int) $row->count;
            } elseif ($role === 'student') {
                $byDate[$date]['students'] = (int) $row->count;
            }
        }

        return response()->json(['data' => $this->buildDailyRange($days, function (string $date) use ($byDate): array {
            return [
                'date' => $date,
                'faculty' => $byDate[$date]['faculty'] ?? 0,
                'students' => $byDate[$date]['students'] ?? 0,
            ];
        })]);
    }

    /**
     * @param  callable(string): array<string, mixed>  $builder
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyRange(int $days, callable $builder): array
    {
        $result = [];
        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::now()->subDays($days - 1 - $i)->format('Y-m-d');
            $result[] = $builder($date);
        }

        return $result;
    }

    private function clampDays(mixed $raw): int
    {
        return max(7, min(90, (int) ($raw ?? 30)));
    }
}

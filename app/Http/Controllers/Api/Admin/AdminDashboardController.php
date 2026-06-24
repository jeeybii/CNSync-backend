<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\StudentDeck;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $totalFaculty = User::query()->where('role', UserRole::Faculty)->count();
        $totalStudents = User::query()->where('role', UserRole::Student)->count();
        $totalExams = Assessment::query()->count();
        $totalReviewerDecks = StudentDeck::query()->where('status', 'ready')->count();

        $systemStatus = $this->buildSystemStatus();

        return response()->json([
            'data' => [
                'total_faculty' => $totalFaculty,
                'total_students' => $totalStudents,
                'total_exams' => $totalExams,
                'total_reviewer_decks' => $totalReviewerDecks,
                'system_status' => $systemStatus,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSystemStatus(): array
    {
        $dbOk = true;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable) {
            $dbOk = false;
        }

        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'database' => $dbOk ? 'connected' : 'error',
            'queue_driver' => (string) config('queue.default'),
            'storage_driver' => (string) config('filesystems.default'),
            'environment' => (string) config('app.env'),
        ];
    }
}

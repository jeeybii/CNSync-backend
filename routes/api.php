<?php

use App\Http\Controllers\Api\Admin\AdminActivityLogController;
use App\Http\Controllers\Api\Admin\AdminChartController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminSystemSettingController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\QuestionItemController;
use App\Http\Controllers\Api\QuestionRunController;
use App\Http\Controllers\Api\StudentDeckController;
use App\Http\Controllers\Api\StudentStudySessionController;
use App\Http\Controllers\Api\TosController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'faculty'])->group(function (): void {
    Route::get('assessments', [AssessmentController::class, 'index']);
    Route::post('assessments', [AssessmentController::class, 'store']);
    Route::get('assessments/{assessment}', [AssessmentController::class, 'show']);
    Route::patch('assessments/{assessment}', [AssessmentController::class, 'update']);
    Route::delete('assessments/{assessment}', [AssessmentController::class, 'destroy']);

    Route::apiResource('projects', ProjectController::class);
    Route::get('projects/{project}/documents', [DocumentController::class, 'index']);
    Route::post('projects/{project}/documents', [DocumentController::class, 'store']);
    Route::post('projects/{project}/documents/bundle', [DocumentController::class, 'storeBundle']);
    Route::get('projects/{project}/documents/{document}', [DocumentController::class, 'show']);
    Route::get('projects/{project}/documents/{document}/transitions', [DocumentController::class, 'transitions']);
    Route::get('projects/{project}/documents/{document}/syllabus-topics', [DocumentController::class, 'syllabusTopics']);
    Route::post('projects/{project}/documents/{document}/extract-syllabus', [DocumentController::class, 'extractSyllabus']);
    Route::put('projects/{project}/documents/{document}/syllabus-topics', [DocumentController::class, 'updateSyllabusTopics']);
    Route::delete('projects/{project}/documents/{document}', [DocumentController::class, 'destroy']);
    Route::get('projects/{project}/tos-runs', [TosController::class, 'index']);
    Route::post('projects/{project}/tos-runs', [TosController::class, 'store']);
    Route::post('projects/{project}/tos-runs/from-syllabus', [TosController::class, 'storeFromSyllabus']);
    Route::get('projects/{project}/tos-runs/{tosRun}', [TosController::class, 'show']);
    Route::get('projects/{project}/question-runs', [QuestionRunController::class, 'index']);
    Route::post('projects/{project}/question-runs', [QuestionRunController::class, 'store']);
    Route::get('projects/{project}/question-runs/{questionRun}', [QuestionRunController::class, 'show']);
    Route::post('projects/{project}/question-runs/{questionRun}/items', [QuestionItemController::class, 'store']);
    Route::put('projects/{project}/question-runs/{questionRun}/items/{questionItem}', [QuestionItemController::class, 'update']);
    Route::delete('projects/{project}/question-runs/{questionRun}/items/{questionItem}', [QuestionItemController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function (): void {
    Route::get('admin/dashboard', [AdminDashboardController::class, 'index']);

    Route::get('admin/users', [AdminUserController::class, 'index']);
    Route::post('admin/users', [AdminUserController::class, 'store']);
    Route::get('admin/users/{user}', [AdminUserController::class, 'show']);
    Route::patch('admin/users/{user}', [AdminUserController::class, 'updateProfile']);
    Route::patch('admin/users/{user}/status', [AdminUserController::class, 'updateStatus']);
    Route::patch('admin/users/{user}/password', [AdminUserController::class, 'updatePassword']);
    Route::delete('admin/users/{user}', [AdminUserController::class, 'destroy']);

    Route::get('admin/charts/generation-daily', [AdminChartController::class, 'generationDaily']);
    Route::get('admin/charts/active-users', [AdminChartController::class, 'activeUsersDaily']);

    Route::get('admin/activity-logs', [AdminActivityLogController::class, 'index']);
    Route::get('admin/activity-logs/actions', [AdminActivityLogController::class, 'actions']);

    Route::get('admin/settings', [AdminSystemSettingController::class, 'index']);
    Route::patch('admin/settings/{systemSetting}', [AdminSystemSettingController::class, 'update']);
});

Route::middleware(['auth:sanctum', 'student'])->group(function (): void {
    Route::get('student/decks', [StudentDeckController::class, 'index']);
    Route::post('student/decks', [StudentDeckController::class, 'store']);
    Route::get('student/decks/{studentDeck}', [StudentDeckController::class, 'show']);
    Route::delete('student/decks/{studentDeck}', [StudentDeckController::class, 'destroy']);

    Route::get('student/decks/{studentDeck}/sessions', [StudentStudySessionController::class, 'index']);
    Route::post('student/decks/{studentDeck}/sessions', [StudentStudySessionController::class, 'store']);
    Route::patch('student/decks/{studentDeck}/sessions/{session}', [StudentStudySessionController::class, 'update']);
});

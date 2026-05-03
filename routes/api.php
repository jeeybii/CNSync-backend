<?php

use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeckController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\QuestionItemController;
use App\Http\Controllers\Api\QuestionRunController;
use App\Http\Controllers\Api\TosController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('decks', [DeckController::class, 'index']);
    Route::get('decks/{deck}', [DeckController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'faculty'])->group(function (): void {
    Route::get('assessments', [AssessmentController::class, 'index']);
    Route::post('assessments', [AssessmentController::class, 'store']);
    Route::get('assessments/{assessment}', [AssessmentController::class, 'show']);
    Route::patch('assessments/{assessment}', [AssessmentController::class, 'update']);
    Route::delete('assessments/{assessment}', [AssessmentController::class, 'destroy']);
    Route::post('decks', [DeckController::class, 'store']);
    Route::delete('decks/{deck}', [DeckController::class, 'destroy']);

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

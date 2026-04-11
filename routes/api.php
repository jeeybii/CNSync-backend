<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'faculty'])->group(function (): void {
    Route::apiResource('projects', ProjectController::class);
    Route::get('projects/{project}/documents', [DocumentController::class, 'index']);
    Route::post('projects/{project}/documents', [DocumentController::class, 'store']);
    Route::get('projects/{project}/documents/{document}', [DocumentController::class, 'show']);
    Route::delete('projects/{project}/documents/{document}', [DocumentController::class, 'destroy']);
});

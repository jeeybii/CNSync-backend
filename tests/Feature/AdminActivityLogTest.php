<?php

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\User;

function activityLogAdminHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

test('admin can filter all generation activity with one filter', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    ActivityLog::query()->create(['action' => 'login', 'description' => 'Signed in']);
    $examLog = ActivityLog::query()->create([
        'action' => 'exam_generated',
        'description' => 'Generated an exam',
    ]);
    $reviewerLog = ActivityLog::query()->create([
        'action' => 'reviewer_deck_generated',
        'description' => 'Generated a reviewer deck',
    ]);

    $response = $this->getJson(
        '/api/admin/activity-logs?action=generation',
        activityLogAdminHeader($admin),
    )->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonCount(2, 'data.data');

    expect(collect($response->json('data.data'))->pluck('id')->all())
        ->toContain($examLog->id, $reviewerLog->id);
});

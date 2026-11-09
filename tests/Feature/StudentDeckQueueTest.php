<?php

use App\Jobs\GenerateStudentDeckJob;
use App\Models\StudentDeck;
use App\Models\User;
use App\Services\StudentDeckGeneratorService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function studentDeckAuthHeader(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('student-test')->plainTextToken];
}

test('student deck generation is dispatched to its own queue', function () {
    Queue::fake();
    Storage::fake('local');

    $user = User::factory()->student()->create();
    $response = $this->post('/api/student/decks', [
        'title' => 'Networking Reviewer',
        'number_of_items' => 1,
        'question_type_distribution' => ['multiple_choice' => 1],
        'materials' => [UploadedFile::fake()->createWithContent('networking.txt', 'Routers forward packets.')],
    ], studentDeckAuthHeader($user));

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.number_of_items', 1);

    $deckId = (int) $response->json('data.id');
    Queue::assertPushedOn(
        'student-generation',
        GenerateStudentDeckJob::class,
        fn (GenerateStudentDeckJob $job): bool => $job->studentDeckId === $deckId
            && $job->numberOfItems === 1
            && count($job->materials) === 1,
    );
    Storage::disk('local')->assertExists(
        Queue::pushed(GenerateStudentDeckJob::class)->first()->materials[0]['path'],
    );
});

test('student generation job completes the pending deck and removes temporary materials', function () {
    Storage::fake('local');

    $user = User::factory()->student()->create();
    $deck = StudentDeck::query()->create([
        'user_id' => $user->id,
        'title' => 'Queued Reviewer',
        'status' => 'processing',
        'number_of_items' => 1,
    ]);
    $path = "student-decks/{$deck->id}/material.txt";
    Storage::disk('local')->put($path, 'Routers forward packets.');

    $generator = new class extends StudentDeckGeneratorService
    {
        public function extractTextFromFile(UploadedFile $file): string
        {
            return 'Routers forward packets.';
        }

        public function generate(array $chunks, int $count, ?array $questionTypeDistribution = null): array
        {
            return [[
                'sequence' => 1,
                'question_text' => 'What forwards packets between networks?',
                'question_type' => 'multiple_choice',
                'options' => ['Router', 'Keyboard', 'Monitor', 'Printer'],
                'answer_key' => 'A',
                'hint' => 'It uses a routing table.',
            ]];
        }
    };

    $job = new GenerateStudentDeckJob(
        $deck->id,
        [['path' => $path, 'name' => 'material.txt', 'mime' => 'text/plain']],
        1,
        ['multiple_choice' => 1],
        '127.0.0.1',
    );
    $job->handle($generator);

    $deck->refresh();
    expect($deck->status)->toBe('ready')
        ->and($deck->number_of_items)->toBe(1)
        ->and($deck->questions)->toHaveCount(1)
        ->and($deck->failed_reason)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\StudentDeck;
use App\Services\StudentDeckGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GenerateStudentDeckJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    /**
     * @param  array<int, array{path:string,name:string,mime:?string}>  $materials
     * @param  array<string, int>|null  $questionTypeDistribution
     */
    public function __construct(
        public int $studentDeckId,
        public array $materials,
        public int $numberOfItems,
        public ?array $questionTypeDistribution,
        public ?string $ipAddress,
    ) {
        $this->onQueue('student-generation');
    }

    public function handle(StudentDeckGeneratorService $generator): void
    {
        $deck = StudentDeck::query()->find($this->studentDeckId);
        if ($deck === null) {
            $this->deleteTemporaryMaterials();

            return;
        }

        if ($deck->status === 'ready') {
            $this->deleteTemporaryMaterials();

            return;
        }

        try {
            $allChunks = [];
            foreach ($this->materials as $material) {
                $absolutePath = Storage::disk('local')->path($material['path']);
                if (! is_file($absolutePath)) {
                    throw new RuntimeException(sprintf('Uploaded material "%s" is no longer available.', $material['name']));
                }

                $file = new UploadedFile(
                    $absolutePath,
                    $material['name'],
                    $material['mime'] ?? null,
                    null,
                    true,
                );
                $text = $generator->extractTextFromFile($file);
                $allChunks = array_merge($allChunks, $generator->chunkText($text));
            }

            if ($allChunks === []) {
                throw new RuntimeException('No readable text content found in the uploaded files.');
            }

            $questions = $generator->generate(
                $allChunks,
                $this->numberOfItems,
                $this->questionTypeDistribution,
            );

            $deck->update([
                'status' => 'ready',
                'number_of_items' => count($questions),
                'questions' => $questions,
                'failed_reason' => null,
            ]);

            ActivityLog::record(
                $deck->user_id,
                'reviewer_deck_generated',
                sprintf('Student generated reviewer deck "%s" (%d questions)', $deck->title, $deck->number_of_items),
                ['student_deck_id' => $deck->id, 'item_count' => $deck->number_of_items],
                $this->ipAddress,
            );

            $this->deleteTemporaryMaterials();
        } catch (Throwable $exception) {
            if ($this->attempts() < $this->tries) {
                $deck->update([
                    'status' => 'processing',
                    'failed_reason' => 'Automatically retrying student generation.',
                ]);
            }

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        StudentDeck::query()
            ->whereKey($this->studentDeckId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'failed_reason' => $exception->getMessage(),
            ]);

        $this->deleteTemporaryMaterials();
    }

    private function deleteTemporaryMaterials(): void
    {
        foreach ($this->materials as $material) {
            Storage::disk('local')->delete($material['path']);
        }

        Storage::disk('local')->deleteDirectory('student-decks/'.$this->studentDeckId);
    }
}

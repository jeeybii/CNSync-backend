<?php

namespace App\Jobs;

use App\Http\Controllers\Api\QuestionRunController;
use App\Models\Project;
use App\Models\QuestionRun;
use App\Models\TosRun;
use App\Models\User;
use App\Services\GeminiQuestionGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class GenerateQuestionRunJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60];

    /**
     * @param  array<string, int>|null  $distribution
     */
    public function __construct(
        public int $questionRunId,
        public int $projectId,
        public int $tosRunId,
        public int $userId,
        public ?array $distribution,
        public ?string $ipAddress,
    ) {
        // Long-running local-model requests must never hold up document parsing.
        $this->onQueue('generation');
    }

    public function handle(
        QuestionRunController $controller,
        GeminiQuestionGenerator $generator,
    ): void {
        $questionRun = QuestionRun::query()->findOrFail($this->questionRunId);
        if (in_array($questionRun->status, ['completed', 'cancelled'], true)) {
            return;
        }

        $project = Project::query()->findOrFail($this->projectId);
        $tosRun = TosRun::query()->with('allocations')->findOrFail($this->tosRunId);
        $user = User::query()->findOrFail($this->userId);
        $request = Request::create('/queued-question-generation', 'POST');
        $request->setUserResolver(fn (): User => $user);
        if ($this->ipAddress !== null) {
            $request->server->set('REMOTE_ADDR', $this->ipAddress);
        }

        try {
            $response = $controller->generateForTosRun(
                $request,
                $project,
                $tosRun,
                $this->distribution,
                $generator,
                $questionRun,
            );
        } catch (Throwable $exception) {
            if ($questionRun->fresh()->status === 'cancelled') {
                return;
            }

            if ($this->attempts() < $this->tries) {
                $questionRun->refresh()->update([
                    'status' => 'processing',
                    'failed_reason' => 'Automatically retrying from the last saved batch.',
                ]);
            }

            throw $exception;
        }

        if ($questionRun->fresh()->status === 'cancelled') {
            return;
        }

        if ($response->getStatusCode() >= 400) {
            $payload = $response->getData(true);
            $message = $payload['message'] ?? 'Question generation failed.';
            $questionRun->refresh()->update([
                'status' => $this->attempts() < $this->tries ? 'processing' : 'failed',
                'failed_reason' => $this->attempts() < $this->tries
                    ? 'Automatically retrying from the last saved batch.'
                    : $message,
            ]);

            throw new RuntimeException($message);
        }
    }

    public function failed(Throwable $exception): void
    {
        QuestionRun::query()
            ->whereKey($this->questionRunId)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'failed_reason' => $exception->getMessage(),
            ]);
    }
}

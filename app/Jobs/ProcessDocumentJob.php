<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $documentId
    ) {}

    public function handle(): void
    {
        $document = Document::query()->find($this->documentId);

        if (! $document) {
            return;
        }

        $document->update(['status' => DocumentStatus::Processing]);

        // Stub: real parsing / chunking will run here later.
        $document->update([
            'status' => DocumentStatus::Ready,
            'failed_reason' => null,
        ]);
    }
}
